<?php
namespace Ps14\Component\Component;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\View\TemplatePaths;

class ComponentCollection extends AbstractComponentCollection
{

    public function getTemplatePaths(): TemplatePaths {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths([
            ExtensionManagementUtility::extPath('ps14_component', 'Resources/Private/Components')
        ]);

        return $templatePaths;
    }


    /**
     * @see https://docs.typo3.org/other/typo3fluid/fluid/main/en-us/Usage/Components.html#components-folder-structure
     */
    public function resolveTemplateName(string $viewHelperName): string
    {
        $fragments = array_map('ucfirst', explode('.', $viewHelperName));
        return implode('/', $fragments);
    }
}
