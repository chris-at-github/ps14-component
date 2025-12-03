<?php
namespace Ps14\Component\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

class CssClassCollectViewHelper extends AbstractViewHelper
{
    public function initializeArguments()
    {
        $this->registerArgument('name', 'string', 'Name der Variable für die CSS-Klassen', true);
        $this->registerArgument('value', 'string', 'CSS-Klasse, die hinzugefügt werden soll', true);
        $this->registerArgument('condition', 'bool', 'Optional: Bedingung, ob die Klasse hinzugefügt wird', false, true);
    }

    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $name = $arguments['name'];
        $value = $arguments['value'];
        $condition = $arguments['condition'];

        // 1. Wenn Wert leer, abbrechen
        if(empty($value) === true) {
            return;
        }

        // 2. Bedingung prüfen: Wenn gesetzt und false, abbrechen
        if(isset($condition) && $condition === false) {
            return;
        }

        // 3. Bestehenden Wert holen (falls vorhanden)
        // Variable entfernen, um sie gleich mit neuem Wert wieder zu setzen
        // (vermeidet Exception bei "add" wenn Variable schon existiert)
        $currentValue = '';
        $variableProvider = $renderingContext->getVariableProvider();

        if($variableProvider->exists($name) === true) {
            $currentValue = (string)$variableProvider->get($name);
            $variableProvider->remove($name);
        }

        // 4. Neuen Wert zusammenbauen (mit Leerzeichen-Trennung)
        if(empty($currentValue) === false) {
            $newValue = $currentValue . ' ' . $value;
        } else {
            $newValue = $value;
        }

        // 5. Variable im Context speichern
        $variableProvider->add($name, $newValue);
    }
}