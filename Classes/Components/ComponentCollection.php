<?php

declare(strict_types=1);

namespace Ps14\Component\Components;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\View\TemplatePaths;

/**
 * Fluid component collection for the namespace
 * "http://typo3.org/ns/Ps14/Component/Components/ComponentCollection".
 *
 * Components are resolved from ps14_component first and can be overridden by
 * the sitepackage ps14_site with a file at the same relative path (the last
 * template root path takes precedence).
 */
final class ComponentCollection extends AbstractComponentCollection
{
    private const OVERRIDE_EXTENSION = 'ps14_site';

    #[\Override]
    public function getTemplatePaths(): TemplatePaths
    {
        $rootPaths = [ExtensionManagementUtility::extPath('ps14_component', 'Resources/Private/Components')];

        if (ExtensionManagementUtility::isLoaded(self::OVERRIDE_EXTENSION)) {
            $rootPaths[] = ExtensionManagementUtility::extPath(self::OVERRIDE_EXTENSION, 'Resources/Private/Components');
        }

        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths($rootPaths);

        return $templatePaths;
    }

    /**
     * Maps "button.icon" to "Button/Icon".
     *
     * @see https://docs.typo3.org/other/typo3fluid/fluid/main/en-us/Usage/Components.html#components-folder-structure
     */
    #[\Override]
    public function resolveTemplateName(string $viewHelperName): string
    {
        return implode('/', array_map(ucfirst(...), explode('.', $viewHelperName)));
    }

    /**
     * Lists all components consistently with resolveTemplateName(),
     * e.g. "Button/Icon.html" becomes "button.icon".
     *
     * @return string[]
     */
    #[\Override]
    public function getAvailableComponents(): array
    {
        $templatePaths = $this->getTemplatePaths();
        $components = [];

        foreach ($templatePaths->resolveAvailableTemplateFiles(null, null, true) as $templateFile) {
            foreach ($templatePaths->getTemplateRootPaths() as $rootPath) {
                if (str_starts_with($templateFile, $rootPath)) {
                    $templateFile = substr($templateFile, strlen($rootPath));
                    break;
                }
            }

            $templateName = (string)preg_replace('/\.html$/', '', $templateFile);
            $components[] = implode('.', array_map(lcfirst(...), explode('/', $templateName)));
        }

        return array_values(array_unique($components));
    }
}
