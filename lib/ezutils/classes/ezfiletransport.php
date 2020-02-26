<?php
/**
 * File containing the eZFileTransport class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 * @package lib
 */

/*!
  \class eZFileTransport ezfiletransport.php
  \brief Sends the email message to a file.

*/

class eZFileTransport extends eZMailTransport
{
    function sendMail( eZMail $mail )
    {
        $filename = time() . '-' . mt_rand() . '.mail';

        $data = preg_replace('/(\r\n|\r|\n)/', "\r\n", $mail->headerText() . "\n" . $mail->body() );
        $returnedValue = eZFile::create( $filename, 'var/log/mail', $data );
        if ( $returnedValue === false )
        {
            eZDebug::writeError( 'An error occurred writing the e-mail file in var/log/mail', __METHOD__ );
        }

        return $returnedValue;
    }
}

?>
