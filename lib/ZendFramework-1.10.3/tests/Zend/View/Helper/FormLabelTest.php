<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_View
 * @subpackage UnitTests
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: FormLabelTest.php 20096 2010-01-06 02:05:09Z bkarwin $
 */

// Call Zend_View_Helper_FormLabelTest::main() if this source file is executed directly.
if (!defined("PHPUnit_MAIN_METHOD")) {
    define("PHPUnit_MAIN_METHOD", "Zend_View_Helper_FormLabelTest::main");
}

require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/TestHelper.php';
require_once "PHPUnit/Framework/TestCase.php";
require_once "PHPUnit/Framework/TestSuite.php";

require_once 'Zend/View.php';
require_once 'Zend/View/Helper/FormLabel.php';

/**
 * Test class for Zend_View_Helper_FormLabel.
 * Generated by PHPUnit_Util_Skeleton on 2007-05-16 at 16:09:28.
 *
 * @category   Zend
 * @package    Zend_View
 * @subpackage UnitTests
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @group      Zend_View
 * @group      Zend_View_Helper
 */
class Zend_View_Helper_FormLabelTest extends PHPUnit_Framework_TestCase
{
    /**
     * Runs the test methods of this class.
     *
     * @access public
     * @static
     */
    public static function main()
    {
        require_once "PHPUnit/TextUI/TestRunner.php";

        $suite  = new PHPUnit_Framework_TestSuite("Zend_View_Helper_FormLabelTest");
        $result = PHPUnit_TextUI_TestRunner::run($suite);
    }

    /**
     * Sets up the fixture, for example, open a network connection.
     * This method is called before a test is executed.
     *
     * @access protected
     */
    protected function setUp()
    {
        $this->view = new Zend_View();
        $this->helper = new Zend_View_Helper_FormLabel();
        $this->helper->setView($this->view);
    }

    /**
     * Tears down the fixture, for example, close a network connection.
     * This method is called after a test is executed.
     *
     * @access protected
     */
    protected function tearDown()
    {
    }

    public function testFormLabelWithSaneInput()
    {
        $label = $this->helper->formLabel('foo', 'bar');
        $this->assertEquals('<label for="foo">bar</label>', $label);
    }

    public function testFormLabelWithInputNeedingEscapesUsesViewEscaping()
    {
        $label = $this->helper->formLabel('<&foo', '</bar>');
        $expected = '<label for="' . $this->view->escape('<&foo') . '">' . $this->view->escape('</bar>') . '</label>';
        $this->assertEquals($expected, $label);
    }

    public function testViewIsSetAndSameAsCallingViewObject()
    {
        $this->assertTrue(isset($this->helper->view));
        $this->assertTrue($this->helper->view instanceof Zend_View_Interface);
        $this->assertSame($this->view, $this->helper->view);
    }

    public function testAttribsAreSet()
    {
        $label = $this->helper->formLabel('foo', 'bar', array('class' => 'baz'));
        $this->assertEquals('<label for="foo" class="baz">bar</label>', $label);
    }

    public function testNameAndIdForZF2154()
    {
        $label = $this->helper->formLabel('name', 'value', array('id' => 'id'));
        $this->assertEquals('<label for="id">value</label>', $label);
    }

    /**
     * @group ZF-2473
     */
    public function testCanDisableEscapingLabelValue()
    {
        $label = $this->helper->formLabel('foo', '<b>Label This!</b>', array('escape' => false));
        $this->assertContains('<b>Label This!</b>', $label);
        $label = $this->helper->formLabel(array('name' => 'foo', 'value' => '<b>Label This!</b>', 'escape' => false));
        $this->assertContains('<b>Label This!</b>', $label);
        $label = $this->helper->formLabel(array('name' => 'foo', 'value' => '<b>Label This!</b>', 'attribs' => array('escape' => false)));
        $this->assertContains('<b>Label This!</b>', $label);
    }

    /**
     * @group ZF-6426
     */
    public function testHelperShouldAllowSuppressionOfForAttribute()
    {
        $label = $this->helper->formLabel('foo', 'bar', array('disableFor' => true));
        $this->assertNotContains('for="foo"', $label);
    }

    /**
     * @group ZF-8265
     */
    public function testShouldNotRenderDisableForAttributeIfForIsSuppressed()
    {
        $label = $this->helper->formLabel('foo', 'bar', array('disableFor' => true));
        $this->assertNotContains('disableFor=', $label, 'Output contains disableFor attribute!');
    }
}

// Call Zend_View_Helper_FormLabelTest::main() if this source file is executed directly.
if (PHPUnit_MAIN_METHOD == "Zend_View_Helper_FormLabelTest::main") {
    Zend_View_Helper_FormLabelTest::main();
}