<?php

namespace WebHookBundle\Installer;

use Doctrine\DBAL\Migrations\AbortMigrationException;
use Doctrine\DBAL\Migrations\Version;
use Doctrine\DBAL\Schema\Schema;
use Pimcore\Db\ConnectionInterface;
use Pimcore\Extension\Bundle\Installer\AbstractInstaller;
use Pimcore\Extension\Bundle\Installer\MigrationInstaller;
use Pimcore\Log\ApplicationLogger;
use Pimcore\Migrations\MigrationManager;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Service;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;
use Random\RandomException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class WebHookBundleInstaller extends AbstractInstaller
{
    public function install(): void
    {
        $this->installClasses();
        $this->installKeys();
    }

    public function uninstall(): void
    {

    }

    /**
     * @throws RandomException
     */
    private function installKeys()
    {

        if (!\Pimcore\Model\WebsiteSetting::getByName('WebHookApi-key')) {
            $settingApiKey = new \Pimcore\Model\WebsiteSetting();
            $settingApiKey->setName("WebHookApi-key");
            $settingApiKey->setType("text");
            $settingApiKey->setData(md5(random_bytes(300)));
            $settingApiKey->save();
        } else {
            $this->output->writeln('Found WebHookApi-key');
        }

        if (\Pimcore\Model\WebsiteSetting::getByName('WebHookPublicKey') && \Pimcore\Model\WebsiteSetting::getByName('WebHookPrivateKey')) {
            $this->output->writeln('Found public and private key');
        } else {
            $new_key_pair = openssl_pkey_new(array(
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ));

            openssl_pkey_export($new_key_pair, $privateKey);

            $details = openssl_pkey_get_details($new_key_pair);
            $publicKey = $details['key'];

            if ($settingPublicKey = \Pimcore\Model\WebsiteSetting::getByName('WebHookPublicKey')) {
                $settingPublicKey->delete();
            }
            $settingPublicKey = new \Pimcore\Model\WebsiteSetting();
            $settingPublicKey->setName("WebHookPublicKey");
            $settingPublicKey->setType("text");
            $settingPublicKey->setData($publicKey);
            $settingPublicKey->save();

            if ($settingPrivateKey = \Pimcore\Model\WebsiteSetting::getByName('WebHookPrivateKey')) {
                $settingPrivateKey->delete();
            }
            $settingPrivateKey = new \Pimcore\Model\WebsiteSetting();
            $settingPrivateKey->setName("WebHookPrivateKey");
            $settingPrivateKey->setType("text");
            $settingPrivateKey->setData($privateKey);
            $settingPrivateKey->save();
        }
    }

    private function installClasses()
    {
        $installSourcesPath = __DIR__ . "/../Resources/install";
        $classesToInstall = [
            "WebHook" => "WB_WebHook"
        ];

        $classes = [];
        foreach ($classesToInstall as $className => $classIdentifier) {
            $filename = sprintf('class_%s_export.json', $className);
            $path = $installSourcesPath . "/class_sources/" . $filename;
            $path = realpath($path);

            if (false === $path || !is_file($path)) {
                $this->output->write(sprintf(
                    'Class "%s" was expected in "%s" but file does not exist', $className, $path
                ));
                continue;
            }
            $classes[$className] = $path;
        }

        foreach ($classes as $key => $path) {
            $class = ClassDefinition::getByName($key);

            if ($class) {
                $this->output->write(sprintf('     <comment>WARNING:</comment> Skipping class "%s" as it already exists', $key));
                continue;
            }

            $class = new ClassDefinition();
            $classIdentifier = $classesToInstall[$key];
            $class->setName($key);
            $class->setId($classIdentifier);

            $data = file_get_contents($path);
            $success = Service::importClassDefinitionFromJson($class, $data, false, true);

            if (!$success) {
                $this->output->write(sprintf(
                    'Failed to create class "%s"',
                    $key
                ));
            }
        }
    }

    public function canBeUninstalled(): bool
    {
        return true; // this can be customized
    }

    public function canBeInstalled(): bool
    {
        return true; // this can be customized
    }
}