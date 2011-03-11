<?php
//  Spec for PHP
//  Copyright (C) 2011 Iván -DrSlump- Montes <drslump@pollinimini.net>
//
//  This program is free software: you can redistribute it and/or modify
//  it under the terms of the GNU Affero General Public License as
//  published by the Free Software Foundation, either version 3 of the
//  License, or (at your option) any later version.
//
//  This program is distributed in the hope that it will be useful,
//  but WITHOUT ANY WARRANTY; without even the implied warranty of
//  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//  GNU Affero General Public License for more details.
//
//  You should have received a copy of the GNU Affero General Public License
//  along with this program.  If not, see <http://www.gnu.org/licenses/>.

namespace DrSlump\Spec\PHPUnit;

use DrSlump\Spec;

/**
 * Extends a PHPUnit Test Case to adapt it for Spec
 *
 * @package     Spec\PHPUnit
 * @author      Iván -DrSlump- Montes <drslump@pollinimini.net>
 * @see         https://github.com/drslump/Spec
 *
 * @copyright   Copyright 2011, Iván -DrSlump- Montes
 * @license     Affero GPL v3 - http://opensource.org/licenses/agpl-v3
 */
class TestCase extends \PHPUnit_Framework_TestCase
{
    /** @var String */
    protected $title;
    /** @var TestSuite */
    protected $parent;
    /** @var Closure */
    protected $callback;
    /** @var Array */
    protected $annotations = array();
    /** @var int */
    protected $hamcrestAssertCount = 0;


    /**
     * @param String $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @return String
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param TestSuite $parent
     */
    public function setParent(TestSuite $parent)
    {
        $this->parent = $parent;
    }

    /**
     * @return TestSuite
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @param Closure $cb
     */
    public function setCallback($cb)
    {
        $this->callback = $cb;

        // Collect callback annotation
        // @todo Does this actually work?
        $reflFunc = new \ReflectionFunction($cb);
        $docblock = $reflFunc->getDocComment();

        $this->annotations = array();
        if (preg_match_all('/@(?P<name>[A-Za-z_-]+)(?:[ \t]+(?P<value>.*?))?(\*\/|$)/m', $docblock, $matches)) {
            $numMatches = count($matches[0]);

            for ($i = 0; $i < $numMatches; ++$i) {
                $this->annotations[$matches['name'][$i]][] = trim($matches['value'][$i]);
            }
        }
    }

    public function getGroups()
    {
        $ann = $this->getAnnotations();
        $suite = empty($ann['class']['group']) ? array() : $ann['class']['group'];
        $test = empty($ann['method']['group']) ? array() : $ann['method']['group'];

        return array_merge($suite, $test);
    }

    /**
     * Executes the callback for this test
     *
     * @note Arguments will be passed to the callback
     */
    public function runCallback()
    {
        // Check for incomplete or skip annotations
        // @todo Shouldn't this inherit from suite/describe ones?
        $ann = $this->annotations;
        if (isset($ann['skip'])) {
            $this->markTestSkipped(isset($ann['skip'][0]) ? $ann['skip'][0] : '');
        }
        if (isset($ann['todo'])) {
            $this->markTestIncomplete(isset($ann['todo'][0]) ? $ann['todo'][0] : '');
        }
        if (isset($ann['incomplete'])) {
            $this->markTestIncomplete(isset($ann['incomplete'][0]) ? $ann['incomplete'][0] : '');
        }

        try {

            //$args = func_get_args();
            //array_unshift($args, $this);

            $args = array($this);

            // Extract values from message
            preg_match_all('/([\'"<])(.*?)(\1|>)/', $this->getTitle(), $m);
            $args = array_merge($args, $m[2]);

            call_user_func_array($this->callback, $args);

        } catch (\Hamcrest_AssertionError $e) {
            // Normalize stack trace by removing spec:// scheme
            $trace = $e->getTrace();
            foreach ($trace as &$frame) {
                if (!empty($frame['file']) && 0 === strpos($frame['file'], Spec::SCHEME . '://')) {
                    $frame['file'] = substr($frame['file'], strlen(Spec::SCHEME) + 3);
                }
            }

            // Adapt Hamcrest exceptions to PHPUnit's ones
            throw new \PHPUnit_Framework_SyntheticError(
                    $e->getMessage(), $e->getCode(), $e->getFile(), $e->getLine(), $trace
            );
        }
    }



    /**
     * Returns a string representation of the test case.
     *
     * @return string
     */
    public function toString()
    {
        // Fetch titles
        $titles = array();
        $level = $this;
        do {
            $titles[] = $level->getTitle();
        } while ($level = $level->getParent());

        // Remove root suite
        array_pop($titles);

        $titles = array_reverse($titles);
        return implode(" > ", $titles) . $this->getDataSetAsString();
    }

    /**
     * Override to run the test and assert its state.
     *
     * @return mixed
     * @throws RuntimeException
     */
    protected function runTest()
    {
        // To re-use most of PHPUnit's default TestCase class
        // we fake the execution via a class method.
        $this->setName('runCallback');
        return parent::runTest();
    }

    // Loggers will use this method to obtain the name of the test
    public function getName()
    {
        return $this->getTitle();
    }


    /**
     * Returns the annotations for this test.
     *
     * @return array
     */
    public function getAnnotations()
    {
        $parent = $this->getParent()->getAnnotations();
        $result['class'] = $parent['class'];
        $result['method'] = $this->annotations;

        return $result;
    }

    /**
     * Override this method to get the annotation from the closure
     */
    protected function setExpectedExceptionFromAnnotation()
    {
        $ann = $this->annotations;

        $throws = null;
        if (!empty($ann['throws'])) {
            $throws = $ann['throws'][0];
        } else if (!empty($ann['expectedException'])) {
            $throws = $ann['expectedException'][0];
        }

        $class = $code = $message = null;
        $regexp = '/([:.\w\\\\x7f-\xff]+)(?:[\t ]+(\S*))?(?:[\t ]+(\S*))?\s*$/';
        if ($throws && preg_match($regexp, $ann['throws'][0], $m)) {
            if (is_numeric($m[1])) {
                $code = $m[1];
            } else {
                $class = $m[1];
            }
            $message = isset($m[2]) ? $m[2] : null;
            $code = isset($m[3]) ? (int)$m[3] : $code;
        }

        if (!empty($ann['expectedExceptionMessage'])) {
            $message = $ann['expectedExceptionMessage'][0];
        }
        if (!empty($ann['expectedExceptionCode'])) {
            $code = (int)$ann['expectedExceptionCode'][0];
        }

        $this->setExpectedException($class, $message, $code);
    }

    /**
     * Override this method to get the annotation from the closure
     */
    protected function setUseErrorHandlerFromAnnotation()
    {
        // @todo Search parent suites too!
        $ann = $this->annotations;
        if (!empty($ann['errorHandler'])) {
            $enabled = filter_var($ann['errorHandler'][0], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $this->setUseErrorHandler($enabled);
        }
    }

    /**
     * Override this method to get the annotation from the closure
     */
    protected function setUseOutputBufferingFromAnnotation()
    {
        // @todo Search parent suites too!
        $ann = $this->annotations;
        if (!empty($ann['outputBuffering'])) {
            $enabled = filter_var($ann['outputBuffering'][0], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $this->setUseOutputBuffering($enabled);
        }
    }


    // Hooks


    public function setUp()
    {
        $suite = $this->getParent();
        $suite->runBeforeEachCallbacks($this);

        if (class_exists('\Hamcrest_MatcherAssert', false)) {
            $this->hamcrestAssertCount = \Hamcrest_MatcherAssert::getCount();
        }
    }

    public function tearDown()
    {
        if (class_exists('\Hamcrest_MatcherAssert', false)) {
            $this->addToAssertionCount(\Hamcrest_MatcherAssert::getCount() - $this->hamcrestAssertCount);
        }

        $suite = $this->getParent();
        $suite->runAfterEachCallbacks($this);
    }


    public function onAssertPreConditions($cb)
    {
        // @todo
    }

    public function onAssertPostConditions($cb)
    {
        // @todo
    }

}