<?php

declare(strict_types=1);

namespace Ps14\Component\ViewHelpers;

use TYPO3Fluid\Fluid\Core\Parser\ParsingState;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\Variables\VariableProviderInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Erweiterter Slot-ViewHelper mit Unterstützung für "Scoped Slots".
 *
 * Überschreibt den Core-`<f:slot>` (\TYPO3Fluid\Fluid\ViewHelpers\SlotViewHelper)
 * und ergänzt ihn um das Argument `variables`. Damit kann eine Komponente Daten an
 * ihren Slot-Inhalt zurückgeben – vergleichbar mit "Scoped Slots" / "Render Props":
 *
 *     // Komponente Card.html
 *     <f:slot variables="{item: internalItem, index: i}" />
 *
 *     // Aufruf der Komponente
 *     <my:card>
 *         {item.title} (Position {index})
 *     </my:card>
 *
 * Der Slot-Inhalt (`{item.title} …`) wird zur Compile-Zeit als Closure festgehalten
 * und beim Rendern der Komponente per `$slot()` ausgeführt.
 *
 * ----------------------------------------------------------------------------------
 * Warum Reflection nötig ist
 * ----------------------------------------------------------------------------------
 * Entscheidend ist, in WELCHEM VariableProvider die übergebenen Variablen landen
 * müssen: Der Slot-Inhalt gehört syntaktisch zum AUFRUFENDEN Template, nicht zur
 * Komponente. Beim Rendern der Komponente existieren aber zwei getrennte
 * RenderingContexts (mit je eigenem VariableProvider):
 *
 *   - der Kontext der Komponente   ($this->renderingContext hier im ViewHelper)
 *   - der Kontext des Aufrufers     (in der Slot-Closure "eingefroren")
 *
 * Würde man die Variablen in $this->renderingContext (Komponente) schreiben, sähe
 * der Slot-Inhalt sie nicht – er wird im Aufrufer-Kontext ausgewertet. Wir müssen
 * also an den im Slot-Closure gekapselten Aufrufer-Kontext herankommen. Fluid bietet
 * dafür keine öffentliche API, daher der Reflection-Zugriff in
 * resolveSlotVariableProvider().
 *
 * ----------------------------------------------------------------------------------
 * Die zwei Render-Pfade (Ursache des früheren "erst beim 2. Aufruf"-Bugs)
 * ----------------------------------------------------------------------------------
 * Fluid baut die Slot-Closure je nach Cache-Zustand des Aufrufer-Templates anders:
 *
 *   1. UNCACHED (1. Seitenaufruf, Template noch nicht kompiliert):
 *      ComponentAdapter::buildSlotClosure() erzeugt
 *          function () use ($viewHelperNode) { ... $this->renderingContext ... }
 *      Der Kontext hängt am gebundenen $this (dem ComponentAdapter), NICHT als
 *      use()-Variable. -> getStaticVariables() liefert kein 'renderingContext'.
 *
 *   2. CACHED (Folgeaufrufe, kompiliertes Template):
 *      TemplateCompiler::wrapChildNodesInClosure() erzeugt
 *          function () use ($renderingContext) { ... }
 *      Hier IST 'renderingContext' eine use()-Variable und damit über
 *      getStaticVariables() greifbar.
 *
 * Die ursprüngliche Implementierung kannte nur Pfad 2. Beim ersten (uncached)
 * Aufruf griff der Reflection-Trick ins Leere, fiel auf den Komponenten-Kontext
 * zurück und die Variablen fehlten im Slot – erst der gecachte 2. Aufruf
 * funktionierte. resolveSlotVariableProvider() deckt jetzt beide Pfade ab.
 *
 * ----------------------------------------------------------------------------------
 * Achtung: Abhängigkeit von Fluid-Interna
 * ----------------------------------------------------------------------------------
 * Sowohl die use()-Variable `renderingContext` als auch die private Property
 * ComponentAdapter::$renderingContext sind nicht Teil der öffentlichen Fluid-API.
 * Ändert Fluid den Closure-Aufbau, liefert resolveSlotVariableProvider() null und
 * render() fällt auf den Komponenten-Kontext zurück: kein Fatal Error, aber die
 * Variablen sind dann im Slot nicht mehr sichtbar. Bei Fluid-Updates also gezielt
 * den Scoped-Slot-Fall (mit `variables`) testen.
 *
 * @see \TYPO3Fluid\Fluid\Core\Component\ComponentAdapter::buildSlotClosure()         Pfad 1 (uncached)
 * @see \TYPO3Fluid\Fluid\Core\Compiler\TemplateCompiler::wrapChildNodesInClosure()   Pfad 2 (cached)
 */
class SlotViewHelper extends AbstractViewHelper
{
    public const DEFAULT_SLOT = 'default';

    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        // Das bestehende Argument für den Slot-Namen
        $this->registerArgument('name', 'string', 'Name of the slot, can be omitted for default slot', false, self::DEFAULT_SLOT);

        // NEU: Ein Array-Argument für die Variablen, die an den Slot übergeben werden sollen
        $this->registerArgument('variables', 'array', 'Variables to pass to the slot', false, []);
    }

    /**
     * Rendert den Slot-Inhalt und injiziert dabei optional Variablen in dessen Scope.
     *
     * Ablauf:
     *   1. Slot-Closure aus dem ViewHelperVariableContainer holen (von der Komponente
     *      über ComponentRenderer dort hinterlegt). Ist kein Slot gesetzt, "".
     *   2. Ohne `variables` Fast-Path: 1:1-Verhalten des Core-ViewHelpers.
     *   3. Den VariableProvider des AUFRUFER-Kontexts bestimmen (siehe Klassen-Doc
     *      und resolveSlotVariableProvider()), denn nur dort sieht der Slot-Inhalt
     *      die Variablen. Fallback ist der Komponenten-Kontext.
     *   4. Variablen setzen, dabei gleichnamige bestehende Variablen sichern.
     *   5. Slot ausführen.
     *   6. Im finally die Variablen wieder entfernen bzw. gesicherte Werte
     *      wiederherstellen – auch wenn der Slot eine Exception wirft.
     *
     * Hinweis zum Scoping: Wir manipulieren den Aufrufer-VariableProvider direkt
     * (add/remove statt push/pop), weil wir keinen eigenen Scope öffnen können, ohne
     * den der Closure zu verändern. Das Sichern/Wiederherstellen (Schritt 4 + 6)
     * stellt sicher, dass eine im Aufrufer bereits vorhandene, gleichnamige Variable
     * (z. B. ein äußeres `item`) nach dem Slot unverändert weiterbesteht.
     */
    public function render(): string
    {
        $variableContainer = $this->renderingContext->getViewHelperVariableContainer();
        $slot = $variableContainer->get(\TYPO3Fluid\Fluid\ViewHelpers\SlotViewHelper::class, $this->arguments['name']);

        if (!is_callable($slot)) {
            return '';
        }

        $variablesToPass = $this->arguments['variables'];

        // Ohne zu übergebende Variablen exakt wie der Core-ViewHelper verhalten
        // (keine Reflection, kein Overhead).
        if ($variablesToPass === []) {
            return (string)$slot();
        }

        // Der Slot-Body wird im Kontext des AUFRUFENDEN Templates gerendert,
        // nicht im Kontext dieser Komponente. Die Variablen müssen daher in den
        // VariableProvider genau dieses Kontexts. Wo dieser steckt, hängt davon
        // ab, ob das aufrufende Template kompiliert (gecacht) ist oder nicht –
        // siehe resolveSlotVariableProvider() und Klassen-Doc. Beide Fälle
        // aufzulösen behebt das "funktioniert erst beim zweiten (gecachten)
        // Aufruf"-Verhalten. Fallback: Komponenten-Kontext (Variablen dann nicht
        // im Slot sichtbar, aber kein Fehler).
        $targetVariableProvider = $this->resolveSlotVariableProvider($slot)
            ?? $this->renderingContext->getVariableProvider();

        // Bereits vorhandene, gleichnamige Variablen sichern, damit wir sie nach
        // dem Rendern wiederherstellen statt sie zu löschen (Schutz vor dem
        // versehentlichen Überschreiben einer äußeren Variable des Aufrufers).
        $shadowed = [];
        foreach ($variablesToPass as $name => $value) {
            if ($targetVariableProvider->exists($name)) {
                $shadowed[$name] = $targetVariableProvider->get($name);
            }
            $targetVariableProvider->add($name, $value);
        }

        try {
            return (string)$slot();

        } finally {
            // Clean-up: ohne push/pop manipulieren wir den Scope der Closure direkt,
            // daher manuelles Zurücksetzen. Das finally garantiert das auch bei einer
            // Exception innerhalb des Slots (sonst lecken die Variablen in den Scope).
            foreach ($variablesToPass as $name => $value) {
                if (array_key_exists($name, $shadowed)) {
                    $targetVariableProvider->add($name, $shadowed[$name]);
                } else {
                    $targetVariableProvider->remove($name);
                }
            }
        }
    }

    /**
     * Ermittelt den VariableProvider des AUFRUFENDEN Templates aus der Slot-Closure –
     * unabhängig davon, ob das aufrufende Template kompiliert (gecacht) ist oder nicht.
     *
     * Hintergrund und die beiden abgedeckten Closure-Varianten sind ausführlich im
     * Klassen-Docblock beschrieben. Kurz:
     *   - Gecacht  -> Kontext liegt als use()-Variable `renderingContext` vor und ist
     *                 über ReflectionFunction::getStaticVariables() erreichbar.
     *   - Uncached -> Closure ist an den ComponentAdapter gebunden; der Kontext steckt
     *                 in dessen privater Property, erreichbar über getClosureThis()
     *                 plus ReflectionProperty.
     *
     * Greift keiner der Wege (z. B. weil Fluid den Closure-Aufbau geändert hat), wird
     * null zurückgegeben; render() fällt dann bewusst auf den Komponenten-Kontext
     * zurück, statt einen Fehler zu werfen.
     *
     * @param \Closure $slot Die von Fluid bereitgestellte Slot-Closure.
     * @return VariableProviderInterface|null Provider des Aufrufer-Kontexts oder null.
     */
    private function resolveSlotVariableProvider(\Closure $slot): ?VariableProviderInterface
    {
        try {
            $reflection = new \ReflectionFunction($slot);
        } catch (\ReflectionException) {
            return null;
        }

        // Pfad 2 (gecacht): TemplateCompiler::wrapChildNodesInClosure() erzeugt
        // `function () use ($renderingContext) { ... }` – als static-Variable greifbar.
        $renderingContext = $reflection->getStaticVariables()['renderingContext'] ?? null;
        if ($renderingContext instanceof RenderingContextInterface) {
            return $renderingContext->getVariableProvider();
        }

        // Pfad 1 (uncached): ComponentAdapter::buildSlotClosure() bindet die Closure an
        // den ComponentAdapter; der Aufrufer-Kontext liegt dort in der privaten
        // Property `renderingContext` (kein use(), daher Zugriff über das gebundene $this).
        $boundObject = $reflection->getClosureThis();
        if ($boundObject !== null) {
            try {
                $renderingContext = (new \ReflectionProperty($boundObject, 'renderingContext'))
                    ->getValue($boundObject);
                if ($renderingContext instanceof RenderingContextInterface) {
                    return $renderingContext->getVariableProvider();
                }
            } catch (\ReflectionException) {
                // Property existiert nicht (mehr) -> Fallback in render().
            }
        }

        return null;
    }

    public static function nodeInitializedEvent(ViewHelperNode $node, array $arguments, ParsingState $parsingState): void
    {
        // Collect available slots of current template in ParsingState
        // This allows to extract template metadata without triggering a
        // full rendering of the template
        $slotName = isset($arguments['name']) && $arguments['name'] instanceof NodeInterface
            ? (string)$arguments['name']->evaluate(new RenderingContext())
            : self::DEFAULT_SLOT;
        if (!in_array($slotName, $parsingState->getAvailableSlots())) {
            $parsingState->setAvailableSlots([...$parsingState->getAvailableSlots(), $slotName]);
        }
    }
}
