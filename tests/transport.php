<?php
// These checks never connect to a network endpoint.
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
require __DIR__ . '/../app/transport.php';
$denied = ['127.0.0.1','10.0.0.1','172.16.0.1','192.168.1.1','169.254.169.254','100.64.0.1','0.0.0.0','198.18.0.1','224.0.0.1','255.255.255.255'];
foreach ($denied as $ip) { if (public_ip($ip)) throw new RuntimeException('Not blocked: ' . $ip); echo 'PASS: blocked ' . $ip . "\n"; }
if (!public_ip('1.1.1.1')) throw new RuntimeException('Public IPv4 rejected');
try { http_request('https://127.0.0.1/'); throw new LogicException('SSRF not blocked'); } catch (RuntimeException $e) { echo "PASS: local request blocked before cURL\n"; }
echo "12 transport checks passed.\n";
