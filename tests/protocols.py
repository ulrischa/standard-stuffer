#!/usr/bin/env python3
"""Local protocol integration tests. Requires PHP CLI, paramiko and pyftpdlib/OpenSSL."""
import datetime
import json
import os
from pathlib import Path
import select
import socket
import ssl
import subprocess
import sys
import tempfile
import threading

import paramiko
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import TLS_FTPHandler
from pyftpdlib.servers import FTPServer

ROOT = Path(__file__).resolve().parents[1]
PHP = sys.argv[1:] or ['php']
passed = 0

def check(ok, message):
    global passed
    if not ok:
        raise AssertionError(message)
    passed += 1
    print('PASS:', message, flush=True)

def run_php(code, data):
    script = 'require ' + json.dumps(str(ROOT / 'app/core.php')) + ';require ' + json.dumps(str(ROOT / 'app/mapping.php')) + ';require ' + json.dumps(str(ROOT / 'app/deployment.php')) + ';$input=json_decode(stream_get_contents(STDIN),true);' + code
    result = subprocess.run(PHP + ['-r', script], input=json.dumps(data), text=True, capture_output=True, timeout=80)
    if result.returncode:
        raise RuntimeError(result.stderr + result.stdout)
    return result.stdout

with tempfile.TemporaryDirectory(prefix='stuffer-protocol-') as tmp:
    tmp = Path(tmp)
    root = tmp / 'files'
    root.mkdir()
    cert_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'localhost')])
    cert = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject)
            .public_key(cert_key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=1))
            .not_valid_after(datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=1))
            .add_extension(x509.SubjectAlternativeName([x509.DNSName('localhost'), x509.DNSName('example.com')]), False)
            .sign(cert_key, hashes.SHA256()))
    certfile = tmp / 'cert.pem'
    certfile.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    keyfile = tmp / 'key.pem'
    keyfile.write_bytes(cert_key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.TraditionalOpenSSL, serialization.NoEncryption()))
    auth = DummyAuthorizer()
    auth.add_user('tester', 'local-test-password', str(root), perm='elradfmwMT')
    class FTPHandler(TLS_FTPHandler):
        pass
    FTPHandler.authorizer = auth
    FTPHandler.certfile = str(certfile)
    FTPHandler.keyfile = str(keyfile)
    FTPHandler.tls_control_required = True
    FTPHandler.tls_data_required = True
    ftp = FTPServer(('127.0.0.1', 0), FTPHandler)
    ftp_port = ftp.socket.getsockname()[1]
    threading.Thread(target=ftp.serve_forever, kwargs={'timeout': 0.1}, daemon=True).start()

    hostkey = paramiko.RSAKey.generate(2048)
    class SSHServer(paramiko.ServerInterface):
        def check_auth_password(self, username, password):
            return paramiko.AUTH_SUCCESSFUL if username == 'tester' and password == 'local-test-password' else paramiko.AUTH_FAILED
        def get_allowed_auths(self, username):
            return 'password'
        def check_channel_request(self, kind, chanid):
            return paramiko.OPEN_SUCCEEDED if kind == 'session' else paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED
    class SFTPServer(paramiko.SFTPServerInterface):
        def local(self, path):
            path = (root / path.lstrip('/')).resolve()
            if not path.is_relative_to(root):
                raise PermissionError('outside test root')
            return path
        def stat(self, path):
            try:
                return paramiko.SFTPAttributes.from_stat(self.local(path).stat())
            except OSError as error:
                return paramiko.SFTPServer.convert_errno(error.errno)
        lstat = stat
        def open(self, path, flags, attr):
            try:
                fd = os.open(self.local(path), flags, 0o600)
                f = os.fdopen(fd, 'r+b' if flags & os.O_RDWR else ('wb' if flags & os.O_WRONLY else 'rb'))
                handle = paramiko.SFTPHandle(flags)
                handle.readfile = f
                handle.writefile = f
                return handle
            except OSError as error:
                return paramiko.SFTPServer.convert_errno(error.errno)
        def rename(self, oldpath, newpath):
            try:
                os.replace(self.local(oldpath), self.local(newpath))
                return paramiko.SFTP_OK
            except OSError as error:
                return paramiko.SFTPServer.convert_errno(error.errno)
        posix_rename = rename
    ssh_socket = socket.socket()
    ssh_socket.bind(('127.0.0.1', 0))
    ssh_socket.listen()
    ssh_port = ssh_socket.getsockname()[1]
    connections = []
    def serve_ssh():
        while True:
            try:
                sock, _ = ssh_socket.accept()
            except OSError:
                break
            transport = paramiko.Transport(sock)
            connections.append(transport)
            transport.add_server_key(hostkey)
            transport.set_subsystem_handler('sftp', paramiko.SFTPServer, SFTPServer)
            try:
                transport.start_server(server=SSHServer())
            except paramiko.SSHException:
                transport.close()
    threading.Thread(target=serve_ssh, daemon=True).start()
    known = tmp / 'known_hosts'
    known.write_text(f'[localhost]:{ssh_port} {hostkey.get_name()} {hostkey.get_base64()}\n')
    bad_known = tmp / 'bad_known_hosts'
    wrong_key = paramiko.RSAKey.generate(2048)
    bad_known.write_text(f'[localhost]:{ssh_port} {wrong_key.get_name()} {wrong_key.get_base64()}\n')
    for protocol, port in [('ftps', ftp_port), ('sftp', ssh_port)]:
        settings = dict(protocol=protocol, host='localhost', port=port, username='tester', password='local-test-password', remote_path='/map.json', ca_file=str(certfile), known_hosts=str(known))
        for version in (1, 2):
            payload = json.dumps({'version': version})
            result = run_php('$r=deploy_mapping($input["settings"],$input["payload"]);echo $r["sha256"];', dict(settings=settings, payload=payload))
            check((root / 'map.json').read_text() == payload and len(result) == 64, f'{protocol}: deployment {version} uploads, renames and verifies active file')
        check(not list(root.glob('*.upload-*')), f'{protocol}: successful deployment leaves no temporary file')
        bad = dict(settings)
        if protocol == 'ftps':
            bad.pop('ca_file')
        else:
            bad['known_hosts'] = str(bad_known)
        result = run_php('try {deploy_mapping($input,"bad");echo "UNSAFE";} catch (RuntimeException $e) {echo "rejected";}', bad)
        check(result == 'rejected' and (root / 'map.json').read_text() == payload, f'{protocol}: invalid certificate/host key rejected, active file preserved')
        bad = dict(settings, password='wrong-password')
        result = run_php('try {deploy_mapping($input,"bad");echo "UNSAFE";} catch (RuntimeException $e) {echo "rejected";}', bad)
        check(result == 'rejected', f'{protocol}: incorrect credentials rejected')

    # An HTTP CONNECT proxy forwards the pinned public destination to a local TLS fixture.
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(str(certfile), str(keyfile))
    sni = []
    context.set_servername_callback(lambda sock, name, ctx: sni.append(name))
    tls_socket = socket.socket()
    tls_socket.bind(('127.0.0.1', 0))
    tls_socket.listen()
    def serve_tls():
        while True:
            try:
                sock, _ = tls_socket.accept()
                with context.wrap_socket(sock, server_side=True) as conn:
                    conn.recv(65536)
                    conn.sendall(b'HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 15\r\nConnection: close\r\n\r\n<h1>Proxy!</h1>')
            except ssl.SSLError:
                continue
            except OSError:
                break
    threading.Thread(target=serve_tls, daemon=True).start()
    proxy_socket = socket.socket()
    proxy_socket.bind(('127.0.0.1', 0))
    proxy_socket.listen()
    requests = []
    def tunnel(client):
        upstream = None
        try:
            header = b''
            while b'\r\n\r\n' not in header:
                chunk = client.recv(4096)
                if not chunk:
                    return
                header += chunk
            requests.append(header.decode('ascii'))
            upstream = socket.create_connection(tls_socket.getsockname())
            client.sendall(b'HTTP/1.1 200 Connection established\r\n\r\n')
            while True:
                readable, _, _ = select.select([client, upstream], [], [], 10)
                if not readable:
                    return
                for source in readable:
                    data = source.recv(65536)
                    if not data:
                        return
                    (upstream if source is client else client).sendall(data)
        finally:
            client.close()
            if upstream:
                upstream.close()
    def serve_proxy():
        while True:
            try:
                client, _ = proxy_socket.accept()
            except OSError:
                break
            threading.Thread(target=tunnel, args=(client,), daemon=True).start()
    threading.Thread(target=serve_proxy, daemon=True).start()
    proxy_config = dict(url=f'http://127.0.0.1:{proxy_socket.getsockname()[1]}', origin_ca_file=str(certfile))
    code = '$h=curl_init("https://example.com");curl_setopt_array($h,proxy_options($input,"example.com","1.1.1.1")+[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$r=curl_exec($h);echo $r===false?"rejected":$r;'
    result = run_php(code, proxy_config)
    check(result == '<h1>Proxy!</h1>', 'proxy: real CONNECT tunnel fetches TLS HTML')
    check(requests[-1].startswith('CONNECT 1.1.1.1:443 ') and sni[-1] == 'example.com', 'proxy: pinned IP is tunnel target while original host remains TLS identity')
    proxy_config.pop('origin_ca_file')
    check(run_php(code, proxy_config) == 'rejected', 'proxy: untrusted origin TLS certificate rejected')
    ftp.close_all()
    ssh_socket.close()
    tls_socket.close()
    proxy_socket.close()
    for connection in connections:
        connection.close()
print(f'{passed} live protocol checks passed.')
