<?php
require __DIR__ . '/../vendor/autoload.php';

use Symfony\Bundle\FrameworkBundle\Kernel\BrowserClient;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Security\Core\User\InMemoryUser;

$client = Symfony\Bundle\FrameworkBundle\KernelBrowser::createClient();
$client->loginUser(new InMemoryUser('admin', null, ['ROLE_ADMIN']));

// create a GET to /admin/imports and print HTML
$crawler = $client->request('GET', '/admin/imports');
echo "Status: " . $client->getResponse()->getStatusCode() . "\n";
echo "Headers:\n";
foreach ($client->getResponse()->headers->all() as $k => $v) {
    echo "$k: " . implode(', ', $v) . "\n";
}
echo "\nBody:\n";
echo $client->getResponse()->getContent();
