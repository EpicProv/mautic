<?php

namespace Mautic\EmailBundle\Swiftmailer\Transport;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Class MandrillTransport.
 */
class MandrillTransport extends AbstractTokenHttpTransport implements CallbackTransportInterface
{
    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var TransportCallback
     */
    private $transportCallback;

    /**
     * MandrillTransport constructor.
     */
    public function __construct(TranslatorInterface $translator, TransportCallback $transportCallback)
    {
        $this->translator        = $translator;
        $this->transportCallback = $transportCallback;
    }

    /**
     * {@inheritdoc}
     */
    protected function getPayload()
    {
        $metadata     = $this->getMetadata();
        $mauticTokens = $mandrillMergeVars = $mandrillMergePlaceholders = [];

        // Mandrill uses *|PLACEHOLDER|* for tokens so Mautic's need to be replaced
        if (!empty($metadata)) {
            $metadataSet  = reset($metadata);
            $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);

            $mandrillMergeVars = $mandrillMergePlaceholders = [];
            foreach ($mauticTokens as $token) {
                $mandrillMergeVars[$token]         = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $token));
                $mandrillMergePlaceholders[$token] = '*|'.$mandrillMergeVars[$token].'|*';
            }
        }

        $message = $this->messageToArray($mauticTokens, $mandrillMergePlaceholders, true);

        // Not used ATM
        unset($message['headers']);

        $message['from_email'] = $message['from']['email'];
        $message['from_name']  = $message['from']['name'];
        unset($message['from']);

        if (!empty($metadata)) {
            // Mandrill will only send a single email to cc and bcc of the first set of tokens
            // so we have to manually set them as to addresses

            // Problem is that it's not easy to know what email is sent so will tack it at the top
            $insertCcEmailHeader = true;

            $message['html'] = '*|HTMLCCEMAILHEADER|*'.$message['html'];
            if (!empty($message['text'])) {
                $message['text'] = '*|TEXTCCEMAILHEADER|*'.$message['text'];
            }

            // Do not expose all the emails in the if using metadata
            $message['preserve_recipients'] = false;

            $bcc = $message['recipients']['bcc'];
            $cc  = $message['recipients']['cc'];

            // Unset the cc and bcc as they will need to be sent as To with each set of tokens
            unset($message['recipients']['bcc'], $message['recipients']['cc']);
        }

        // Generate the recipients
        $recipients = $rcptMergeVars = $rcptMetadata = [];

        foreach ($message['recipients'] as $type => $typeRecipients) {
            foreach ($typeRecipients as $rcpt) {
                $rcpt['type'] = $type;
                $recipients[] = $rcpt;

                if ('to' == $type && isset($metadata[$rcpt['email']])) {
                    if (!empty($metadata[$rcpt['email']]['tokens'])) {
                        $mergeVars = [
                            'rcpt' => $rcpt['email'],
                            'vars' => [],
                        ];

                        // This must not be included for CC and BCCs
                        $trackingPixelToken = [];

                        foreach ($metadata[$rcpt['email']]['tokens'] as $token => $value) {
                            if ('{tracking_pixel}' == $token) {
                                $trackingPixelToken = [
                                    [
                                        'name'    => $mandrillMergeVars[$token],
                                        'content' => $value,
                                    ],
                                ];

                                continue;
                            }

                            $mergeVars['vars'][] = [
                                'name'    => $mandrillMergeVars[$token],
                                'content' => $value,
                            ];
                        }

                        if (!empty($insertCcEmailHeader)) {
                            // Make a copy before inserted the blank tokens
                            $ccMergeVars       = $mergeVars;
                            $mergeVars['vars'] = array_merge(
                                $mergeVars['vars'],
                                $trackingPixelToken,
                                [
                                    [
                                        'name'    => 'HTMLCCEMAILHEADER',
                                        'content' => '',
                                    ],
                                    [
                                        'name'    => 'TEXTCCEMAILHEADER',
                                        'content' => '',
                                    ],
                                ]
                            );
                        } else {
                            // Just merge the tracking pixel tokens
                            $mergeVars['vars'] = array_merge($mergeVars['vars'], $trackingPixelToken);
                        }

                        // Add the vars
                        $rcptMergeVars[] = $mergeVars;

                        // Special handling of CC and BCC with tokens
                        if (!empty($cc) || !empty($bcc)) {
                            $ccMergeVars['vars'] = array_merge(
                                $ccMergeVars['vars'],
                                [
                                    [
                                        'name'    => 'HTMLCCEMAILHEADER',
                                        'content' => $this->translator->trans(
                                            'mautic.core.email.cc.copy',
                                            [
                                                '%email%' => $rcpt['email'],
                                            ]
                                        ).'<br /><br />',
                                    ],
                                    [
                                        'name'    => 'TEXTCCEMAILHEADER',
                                        'content' => $this->translator->trans(
                                            'mautic.core.email.cc.copy',
                                            [
                                                '%email%' => $rcpt['email'],
                                            ]
                                        )."\n\n",
                                    ],
                                    [
                                        'name'    => 'TRACKINGPIXEL',
                                        'content' => MailHelper::getBlankPixel(),
                                    ],
                                ]
                            );

                            // If CC and BCC, remove the ct from URLs to prevent false lead tracking
                            foreach ($ccMergeVars['vars'] as &$var) {
                                if (false !== strpos($var['content'], 'http') && ($ctPos = strpos($var['content'], 'ct=')) !== false) {
                                    // URL so make sure a ct query is not part of it
                                    $var['content'] = substr($var['content'], 0, $ctPos);
                                }
                            }

                            // Send same tokens to each CC
                            if (!empty($cc)) {
                                foreach ($cc as $ccRcpt) {
                                    $recipients[]        = $ccRcpt;
                                    $ccMergeVars['rcpt'] = $ccRcpt['email'];
                                    $rcptMergeVars[]     = $ccMergeVars;
                                }
                            }

                            // And same to BCC
                            if (!empty($bcc)) {
                                foreach ($bcc as $ccRcpt) {
                                    $recipients[]        = $ccRcpt;
                                    $ccMergeVars['rcpt'] = $ccRcpt['email'];
                                    $rcptMergeVars[]     = $ccMergeVars;
                                }
                            }
                        }

                        unset($ccMergeVars, $mergeVars, $metadata[$rcpt['email']]['tokens']);
                    }

                    if (!empty($metadata[$rcpt['email']])) {
                        $rcptMetadata[] = [
                            'rcpt'   => $rcpt['email'],
                            'values' => $metadata[$rcpt['email']],
                        ];
                        unset($metadata[$rcpt['email']]);
                    }
                }
            }
        }

        $message['to'] = $recipients;

        unset($message['recipients']);

        // Set the merge vars
        if (!empty($rcptMergeVars)) {
            $message['merge_vars'] = $rcptMergeVars;
        }

        // Set the rest of $metadata as recipient_metadata
        $message['recipient_metadata'] = $rcptMetadata;

        // Set the reply to
        if (!empty($message['replyTo'])) {
            $message['headers']['Reply-To'] = $message['replyTo']['email'];
        }
        unset($message['replyTo']);

        $key = $this->getApiKey();

        if (empty($key)) {
            // BC support @deprecated - remove in 3.0
            $key = $this->getPassword();
        }

        // Package it up
        $payload = json_encode(
            [
                'key'     => $key,
                'message' => $message,
            ]
        );

        return $payload;
    }

    /**
     * {@inheritdoc}
     */
    protected function getHeaders()
    {
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiEndpoint()
    {
        return 'https://mandrillapp.com/api/1.0/messages/send.json';
    }

    /**
     * Start this Transport mechanism.
     */
    public function start()
    {
        $key = $this->getApiKey();
        if (empty($key)) {
            // BC support @deprecated - remove in 3.0
            $key = $this->getPassword();
        }

        // Make an API call to the ping endpoint
        $this->post(
            [
                'url'     => 'https://mandrillapp.com/api/1.0/users/ping.json',
                'payload' => json_encode(
                    [
                        'key' => $key,
                    ]
                ),
            ]
        );

        $this->started = true;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     *
     * @throws \Swift_TransportException
     */
    protected function handlePostResponse($response, $info)
    {
        $parsedResponse = '';
        $response       = json_decode($response, true);

        if (false === $response) {
            $parsedResponse = $response;
        }

        if (!$this->started) {
            // Check the response for PONG!
            if ('PONG!' !== $response) {
                $message = 'Mandrill failed to authenticate';
                // array ( 'status' => 'error', 'code' => -1, 'name' => 'Invalid_Key', 'message' => 'Invalid API key', )"
                if (is_array($response) && isset($response['message'])) {
                    $message .= ': '.$response['message'];
                }

                $this->throwException($message);
            }

            return [];
        }

        $return     = [];
        $metadata   = $this->getMetadata();

        if (is_array($response)) {
            if (isset($response['status']) && 'error' == $response['status']) {
                $parsedResponse = $response['message'];
                $error          = true;
            } else {
                foreach ($response as $stat) {
                    if (in_array($stat['status'], ['rejected', 'invalid'])) {
                        $return[]       = $stat['email'];
                        $parsedResponse = "{$stat['email']} => {$stat['status']}\n";

                        if ('invalid' == $stat['status']) {
                            $stat['reject_reason'] = 'invalid';
                        }

                        // Extract lead ID from metadata if applicable
                        $leadId = (!empty($metadata[$stat['email']]['leadId'])) ? $metadata[$stat['email']]['leadId'] : null;

                        if (in_array($stat['reject_reason'], ['hard-bounce', 'soft-bounce', 'reject', 'spam', 'invalid', 'unsub'])) {
                            $type     = ('unsub' == $stat['reject_reason']) ? DoNotContact::UNSUBSCRIBED : DoNotContact::BOUNCED;
                            $comments = ('unsubscribed' == $type) ? $type : str_replace('-', '_', $stat['reject_reason']);
                            $this->transportCallback->addFailureByContactId($leadId, $comments, $type);
                        }
                    }
                }
            }
        }

        if ($evt = $this->getDispatcher()->createResponseEvent($this, $parsedResponse, 200 == $info['http_code'])) {
            $this->getDispatcher()->dispatchEvent($evt, 'responseReceived');
        }

        if (false === $response) {
            $this->throwException('Unexpected response');
        } elseif (!empty($error)) {
            $this->throwException('Mandrill error');
        }

        return $return;
    }

    /**
     * Returns a "transport" string to match the URL path /mailer/{transport}/callback.
     *
     * @return mixed
     */
    public function getCallbackPath()
    {
        return 'mandrill';
    }

    /**
     * @return int
     */
    public function getMaxBatchLimit()
    {
        // Not used by Mandrill API
        return 0;
    }

    /**
     * @param int    $toBeAdded
     * @param string $type
     *
     * @return int
     */
    public function getBatchRecipientCount(\Swift_Message $message, $toBeAdded = 1, $type = 'to')
    {
        // Not used by Mandrill API
        return 0;
    }

    /**
     * Handle response.
     */
    public function processCallbackRequest(Request $request)
    {
        $mandrillEvents = $request->request->get('mandrill_events');
        $mandrillEvents = json_decode($mandrillEvents, true);

        if (is_array($mandrillEvents)) {
            foreach ($mandrillEvents as $event) {
                $isBounce      = in_array($event['event'], ['hard_bounce', 'reject']);
                $isUnsubscribe = in_array($event['event'], ['spam', 'unsub']);
                if ($isBounce || $isUnsubscribe) {
                    $type = ($isBounce) ? DoNotContact::BOUNCED : DoNotContact::UNSUBSCRIBED;

                    if (!empty($event['msg']['diag'])) {
                        $reason = $event['msg']['diag'];
                    } elseif (!empty($event['msg']['bounce_description'])) {
                        $reason = $event['msg']['bounce_description'];
                    } else {
                        $reason = ($isUnsubscribe) ? 'unsubscribed' : $event['event'];
                    }

                    if (isset($event['msg']['metadata']['hashId'])) {
                        $this->transportCallback->addFailureByHashId($event['msg']['metadata']['hashId'], $reason, $type);
                    } else {
                        $this->transportCallback->addFailureByAddress($event['msg']['email'], $reason, $type);
                    }
                }
            }
        }
    }

    /**
     * @return bool
     */
    public function ping()
    {
        return true;
    }
}
