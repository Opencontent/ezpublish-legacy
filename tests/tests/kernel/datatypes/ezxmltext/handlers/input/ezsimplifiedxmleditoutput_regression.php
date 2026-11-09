<?php
/**
 * File containing the eZSimplifiedXMLEditOutputRegression class
 *
 * @package tests
 */

class eZSimplifiedXMLEditOutputRegression extends ezpTestCase
{
    public function __construct()
    {
        parent::__construct();
        $this->setName( __CLASS__ . " tests" );
    }

    /**
     * An ezxmltext attribute stored as an empty string (data_text = '') gives a
     * DOMDocument without a root element: performOutput() used to fatal with
     * "Call to a member function hasAttributes() on null".
     */
    public function testPerformOutputWithEmptyDocument()
    {
        $dom = new DOMDocument( '1.0', 'utf-8' );
        $this->assertNull( $dom->documentElement, 'Precondition: the document has no root element' );

        $handler = new eZSimplifiedXMLEditOutput();

        $this->assertSame( '', $handler->performOutput( $dom ) );
    }

    /**
     * Non-regression: a valid document must still be rendered.
     */
    public function testPerformOutputWithValidDocument()
    {
        $dom = new DOMDocument( '1.0', 'utf-8' );
        $dom->loadXML(
            '<?xml version="1.0" encoding="utf-8"?>' .
            '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/"' .
            ' xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/"' .
            ' xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">' .
            '<paragraph>Hello world</paragraph></section>'
        );

        $handler = new eZSimplifiedXMLEditOutput();

        $this->assertContains( 'Hello world', $handler->performOutput( $dom ) );
    }

    /**
     * The version comparison (content/history) must not fail when one of the
     * two versions (or both) has an empty ezxmltext attribute.
     *
     * @dataProvider provideXmlDataPairs
     */
    public function testVersionDiffWithEmptyXmlText( $oldXml, $newXml )
    {
        $validXml = '<?xml version="1.0" encoding="utf-8"?>' .
            '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/"' .
            ' xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/"' .
            ' xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">' .
            '<paragraph>Hello world</paragraph></section>';

        $engine = new eZDiffXMLTextEngine();

        $diff = $engine->createDifferenceObject(
            new eZSimplifiedXMLEditOutputRegressionAttribute( $oldXml === 'VALID' ? $validXml : $oldXml ),
            new eZSimplifiedXMLEditOutputRegressionAttribute( $newXml === 'VALID' ? $validXml : $newXml )
        );

        $this->assertInstanceOf( 'eZXMLTextDiff', $diff );
    }

    public static function provideXmlDataPairs()
    {
        return array(
            'both empty'    => array( '', '' ),
            'old is empty'  => array( '', 'VALID' ),
            'new is empty'  => array( 'VALID', '' ),
        );
    }
}

/**
 * Minimal stand-ins for eZContentObjectAttribute / eZXMLText, exposing only
 * what eZDiffXMLTextEngine::createDifferenceObject() reads.
 */
class eZSimplifiedXMLEditOutputRegressionAttribute
{
    private $xml;

    public function __construct( $xml )
    {
        $this->xml = $xml;
    }

    public function content()
    {
        return $this;
    }

    public function attribute( $name )
    {
        return $name === 'xml_data' ? $this->xml : null;
    }
}
