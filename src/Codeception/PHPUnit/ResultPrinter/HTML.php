<?php
namespace Codeception\PHPUnit\ResultPrinter;

use Codeception\PHPUnit\ResultPrinter as CodeceptionResultPrinter;
use Codeception\Step;
use Codeception\Step\Meta;
use Codeception\Test\Descriptor;
use Codeception\Test\Interfaces\ScenarioDriven;

class HTML extends CodeceptionResultPrinter
{
    /**
     * @var boolean
     */
    protected $printsHTML = true;

    /**
     * @var integer
     */
    protected $id = 0;

    /**
     * @var string
     */
    protected $scenarios = '';

    /**
     * @var string
     */
    protected $templatePath;

    /**
     * @var int
     */
    protected $timeTaken = 0;

    protected $failures = [];

    /**
     * Constructor.
     *
     * @param  mixed $out
     * @throws InvalidArgumentException
     */
    public function __construct($out = null)
    {
        parent::__construct($out);

        $this->templatePath = sprintf(
            '%s%stemplate%s',
            __DIR__,
            DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * Handler for 'start class' event.
     *
     * @param  string $name
     */
    protected function startClass($name)
    {
    }

    public function endTest(\PHPUnit_Framework_Test $test, $time)
    {
        $steps = [];
        $success = ($this->testStatus == \PHPUnit_Runner_BaseTestRunner::STATUS_PASSED);
        if ($success) {
            $this->successful++;
        }

        if ($test instanceof ScenarioDriven) {
            $steps = $test->getScenario()->getSteps();
        }
        $this->timeTaken += $time;

        switch ($this->testStatus) {
            case \PHPUnit_Runner_BaseTestRunner::STATUS_FAILURE:
                $scenarioStatus = 'scenarioFailed';
                break;
            case \PHPUnit_Runner_BaseTestRunner::STATUS_SKIPPED:
                $scenarioStatus = 'scenarioSkipped';
                break;
            case \PHPUnit_Runner_BaseTestRunner::STATUS_INCOMPLETE:
                $scenarioStatus = 'scenarioIncomplete';
                break;
            case \PHPUnit_Runner_BaseTestRunner::STATUS_ERROR:
                $scenarioStatus = 'scenarioFailed';
                break;
            default:
                $scenarioStatus = 'scenarioSuccess';
        }

        $stepsBuffer = '';
        $metaStep = null;

        $subStepsBuffer = '';

        foreach ($steps as $step) {
            /** @var $step Step  **/
            if ($step->getMetaStep()) {
                $subStepsBuffer .= $this->renderStep($step);
                $metaStep = $step->getMetaStep();
                continue;
            }
            if ($step->getMetaStep() != $metaStep) {
                $stepsBuffer .= $this->renderSubsteps($metaStep, $subStepsBuffer);
                $subStepsBuffer = '';
            }
            $metaStep = $step->getMetaStep();
            $stepsBuffer .= $this->renderStep($step);
        }

        if ($subStepsBuffer and $metaStep) {
            $stepsBuffer .= $this->renderSubsteps($metaStep, $subStepsBuffer);
        }

        $scenarioTemplate = new \Text_Template(
            $this->templatePath . 'scenario.html'
        );

        $failure = '';
        $name = Descriptor::getTestSignature($test);
        if (isset($this->failures[$name])) {
            $failTemplate = new \Text_Template(
                $this->templatePath . 'fail.html'
            );
            $failTemplate->setVar(['fail' => nl2br($this->failures[$name])]);
            $failure = $failTemplate->render();
        }

        $toggle = $stepsBuffer ? '<span class="toggle">+</span>' : '';

        $scenarioTemplate->setVar(
            [
                'id'             => ++$this->id,
                'name'           => ucfirst(Descriptor::getTestAsString($test)),
                'scenarioStatus' => $scenarioStatus,
                'steps'          => $stepsBuffer,
                'toggle'         => $toggle,
                'failure'        => $failure,
                'time'           => round($time, 2)
            ]
        );

        $this->scenarios .= $scenarioTemplate->render();
    }

    public function startTestSuite(\PHPUnit_Framework_TestSuite $suite)
    {
        $suiteTemplate = new \Text_Template(
            $this->templatePath . 'suite.html'
        );
        if (!$suite->getName()) {
            return;
        }

        $suiteTemplate->setVar(['suite' => ucfirst($suite->getName())]);

        $this->scenarios .= $suiteTemplate->render();
    }

    /**
     * Handler for 'end run' event.
     */
    protected function endRun()
    {
        $scenarioHeaderTemplate = new \Text_Template(
            $this->templatePath . 'scenario_header.html'
        );

        $status = !$this->failed
            ? '<span style="color: green">OK</span>'
            : '<span style="color: #e74c3c">FAILED</span>';


        $scenarioHeaderTemplate->setVar(
            [
                'name'   => 'Codeception Results',
                'status' => $status,
                'time'   => round($this->timeTaken, 1)
            ]
        );

        $header = $scenarioHeaderTemplate->render();

        $scenariosTemplate = new \Text_Template(
            $this->templatePath . 'scenarios.html'
        );

        $scenariosTemplate->setVar(
            [
                'header'              => $header,
                'scenarios'           => $this->scenarios,
                'successfulScenarios' => $this->successful,
                'failedScenarios'     => $this->failed,
                'skippedScenarios'    => $this->skipped,
                'incompleteScenarios' => $this->incomplete
            ]
        );

        $this->write($scenariosTemplate->render());
    }

    /**
     * An error occurred.
     *
     * @param \PHPUnit_Framework_Test $test
     * @param \Exception $e
     * @param float $time
     */
    public function addError(\PHPUnit_Framework_Test $test, \Exception $e, $time)
    {
        $this->failures[Descriptor::getTestSignature($test)] = $this->cleanMessage($e);
        parent::addError($test, $e, $time);
    }

    /**
     * A failure occurred.
     *
     * @param PHPUnit_Framework_Test                 $test
     * @param PHPUnit_Framework_AssertionFailedError $e
     * @param float                                  $time
     */
    public function addFailure(\PHPUnit_Framework_Test $test, \PHPUnit_Framework_AssertionFailedError $e, $time)
    {
        $this->failures[Descriptor::getTestSignature($test)] = $this->cleanMessage($e);
        parent::addFailure($test, $e, $time);
    }


    /**
     * @param $step
     * @return string
     */
    protected function renderStep(Step $step)
    {
        $stepTemplate = new \Text_Template($this->templatePath . 'step.html');
        $stepTemplate->setVar(['action' => $step->getHtml(), 'error' => $step->hasFailed() ? 'failedStep' : '']);
        return $stepTemplate->render();
    }

    /**
     * @param $metaStep
     * @param $substepsBuffer
     * @return string
     */
    protected function renderSubsteps(Meta $metaStep, $substepsBuffer)
    {
        $metaTemplate = new \Text_Template($this->templatePath . 'substeps.html');
        $metaTemplate->setVar(['metaStep' => $metaStep, 'error' => $metaStep->hasFailed() ? 'failedStep' : '', 'steps' => $substepsBuffer, 'id' => uniqid()]);
        return $metaTemplate->render();
    }

    private function cleanMessage($exception)
    {
        $msg = $exception->getMessage();
        $msg = str_replace(['<info>','</info>','<bold>','</bold>'], ['','','',''], $msg);
        return htmlentities($msg);
    }
}
