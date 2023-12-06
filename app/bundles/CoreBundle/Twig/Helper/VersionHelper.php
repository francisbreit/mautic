<?php

namespace Mautic\CoreBundle\Twig\Helper;

use Mautic\CoreBundle\Helper\AppVersion;

/**
 * final class VersionHelper.
 */
final class VersionHelper
{
    private \Mautic\CoreBundle\Helper\AppVersion $appVersion;

    public function __construct(AppVersion $appVersion)
    {
        $this->appVersion = $appVersion;
    }

    public function getName(): string
    {
        return 'version';
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->appVersion->getVersion();
    }
}
