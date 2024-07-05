<?php

namespace WebHookBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;
use Pimcore\HttpKernel\Bundle\DependentBundleInterface;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use WebHookBundle\Installer\WebHookBundleInstaller;

class WebHookBundle extends AbstractPimcoreBundle
{
    public function getInstaller(): WebHookBundleInstaller
    {

        return $this->container->get(WebHookBundleInstaller::class);

    }
}
