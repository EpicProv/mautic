<?php

namespace Mautic\EmailBundle\Tests\EventListener;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\EventListener\TokenSubscriber;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\PrimaryCompanyHelper;
use Symfony\Component\EventDispatcher\EventDispatcher;

class TokenSubscriberTest extends \PHPUnit\Framework\TestCase
{
    public function testDynamicContentCustomTokens()
    {
        $mockFactory = $this->getMockBuilder(MauticFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $swiftMailer = $this->getMockBuilder(\Swift_Mailer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $swiftMailer->method('getTransport')->willReturn('');

        $tokens = [
            '{test}' => 'value',
        ];

        $mailHelper = new MailHelper($mockFactory, $swiftMailer);
        $mailHelper->setTokens($tokens);

        $email = new Email();
        $email->setSubject('Test subject');
        $email->setCustomHtml(
            <<<'CONTENT'
<html xmlns="http://www.w3.org/1999/xhtml">
    <body style="margin: 0px; cursor: auto;" class="ui-sortable">
        <div data-section-wrapper="1">
            <center>
                <table data-section="1" style="width: 600;" width="600" cellpadding="0" cellspacing="0">
                    <tbody>
                        <tr>
                            <td>
                                <div data-slot-container="1" style="min-height: 30px">
                                    <div data-slot="text"><br /><h2>Hello there!</h2><br />{test} test We haven't heard from you for a while...<a href="https://google.com">check this link</a><br /><br />{unsubscribe_text} | {webview_text}</div>{dynamiccontent="Dynamic Content 2"}<div data-slot="codemode">
                                    <div id="codemodeHtmlContainer">
    <p>Place your content here {test}</p></div>

                                </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </center>
        </div>
</body></html>
CONTENT
        )
            ->setDynamicContent(
                [
                    [
                        'tokenName' => 'Dynamic Content 1',
                        'content'   => 'Default Dynamic Content',
                        'filters'   => [
                            [
                                'content' => null,
                                'filters' => [
                                ],
                            ],
                        ],
                    ],
                    [
                        'tokenName' => 'Dynamic Content 2',
                        'content'   => 'DEC {test}',
                        'filters'   => [
                        ],
                    ],
                ]
            );
        $mailHelper->setEmail($email);

        $lead = new Lead();
        $lead->setEmail('hello@someone.com');
        $mailHelper->setLead($lead);

        $dispatcher           = new EventDispatcher();
        $primaryCompanyHelper = $this->createMock(PrimaryCompanyHelper::class);
        $primaryCompanyHelper->method('getProfileFieldsWithPrimaryCompany')
            ->willReturn(['email' => 'hello@someone.com']);

        /** @var TokenSubscriber $subscriber */
        $subscriber = $this->getMockBuilder(TokenSubscriber::class)
            ->setConstructorArgs([$dispatcher, $primaryCompanyHelper])
            ->onlyMethods([])
            ->getMock();

        $dispatcher->addSubscriber($subscriber);

        $event = new EmailSendEvent($mailHelper);

        $subscriber->decodeTokens($event);

        $eventTokens = $event->getTokens(false);
        $this->assertEquals(
            $eventTokens,
            [
                '{dynamiccontent="Dynamic Content 1"}' => 'Default Dynamic Content',
                '{dynamiccontent="Dynamic Content 2"}' => 'DEC value',
            ]
        );
        $mailHelper->addTokens($eventTokens);
        $mailerTokens = $mailHelper->getTokens();
        $mailHelper->message->setBody($email->getCustomHtml());
        $mailHelper->message->setSubject($email->getSubject());

        MailHelper::searchReplaceTokens(array_keys($mailerTokens), $mailerTokens, $mailHelper->message);
        $parsedBody = $mailHelper->message->getBody();

        $this->assertNotFalse(strpos($parsedBody, 'DEC value'));
        $this->assertNotFalse(strpos($parsedBody, 'value test We'));
        $this->assertNotFalse(strpos($parsedBody, 'Place your content here value'));
    }
}
