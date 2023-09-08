<?php

namespace Mautic\EmailBundle\Tests\Helper;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Exception\InvalidEmailException;
use Mautic\EmailBundle\Helper\FromEmailHelper;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Exception\BatchQueueMaxException;
use Mautic\EmailBundle\MonitoredEmail\Mailbox;
use Mautic\EmailBundle\Tests\Helper\Transport\BatchTransport;
use Mautic\EmailBundle\Tests\Helper\Transport\BcInterfaceTokenTransport;
use Mautic\EmailBundle\Tests\Helper\Transport\SmtpTransport;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MailHelperTest extends TestCase
{
    private const MINIFY_HTML = '<!doctype html>
    <html lang=3D"en" xmlns=3D"http://www.w3.org/1999/xhtml" xmlns:v=3D"urn:schemas-microsoft-com:vml" xmlns:o=3D"urn:schemas-microsoft-com:office:office">
      <head>
        <title>Test</title>
        <body style=3D"word-spacing:normal;background-color:#FFFFFF;">
            <div  style=3D"background:#FFFFFF;background-color:#FFFFFF;margin:0pxauto;max-width:600px;">
        </body>
    </html>';

    private FromEmailHelper $fromEmailHelper;
    private MailHelper $singleMailHelper;
    private MailHelper $batchMailHelper;

    /**
     * @var MauticFactory&MockObject
     */
    private $mockFactory;

    /**
     * @var CoreParametersHelper&MockObject
     */
    private $coreParametersHelper;

    /**
     * @var Mailbox&MockObject
     */
    private $mailbox;

    /**
     * @var LeadRepository&MockObject
     */
    private $contactRepository;

    /**
     * @var array<array<string,string|int>>
     */
    protected $contacts = [
        [
            'id'        => 1,
            'email'     => 'contact1@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '1',
            'owner_id'  => 1,
        ],
        [
            'id'        => 2,
            'email'     => 'contact2@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '2',
            'owner_id'  => 0,
        ],
        [
            'id'        => 3,
            'email'     => 'contact3@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '3',
            'owner_id'  => 2,
        ],
        [
            'id'        => 4,
            'email'     => 'contact4@somewhere.com',
            'firstname' => 'Contact',
            'lastname'  => '4',
            'owner_id'  => 1,
        ],
    ];

    protected function setUp(): void
    {
        defined('MAUTIC_ENV') or define('MAUTIC_ENV', 'test');

        $this->contactRepository    = $this->createMock(LeadRepository::class);
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->fromEmailHelper      = new FromEmailHelper($this->coreParametersHelper, $this->contactRepository);
        $this->mockFactory          = $this->createMock(MauticFactory::class);
        $this->mailbox              = $this->createMock(Mailbox::class);

        $fromName = 'No Body';

        if ('testMailAsOwnerWithEncodedCharactersInName' === $this->getName()) {
            $fromName = 'No Body&#39;s Business';
        }

        $params = [
            ['mailer_from_email', null, 'nobody@nowhere.com'],
            ['mailer_from_name', null, $fromName],
        ];

        if ('testEmailReplyToWithFromAndGlobalEmail' === $this->getName()) {
            $params[] = ['mailer_reply_to_email', null, 'admin@mautic.com'];
        }

        if (in_array($this->getName(), ['testGlobalHeadersAreMergedIfEmailEntityIsSet', 'testEmailHeadersAreSet', 'testGlobalHeadersAreSet'])) {
            $params[] = ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']];
        }

        if (!str_starts_with($this->getName(), 'testMinifyHtml')) {
            $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));
        }

        $this->batchMailHelper  = new MailHelper($this->mockFactory, new Mailer(new BatchTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $this->singleMailHelper = new MailHelper($this->mockFactory, new Mailer(new BcInterfaceTokenTransport()), $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
    }

    public function testQueueModeThrowsExceptionWhenBatchLimitHit(): void
    {
        $this->expectException(BatchQueueMaxException::class);

        $this->mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                        ['mailer_spool_type', false, 'memory'],
                    ]
                )
            );

        // Enable queue mode
        $this->batchMailHelper->enableQueue();
        $this->batchMailHelper->addTo('somebody@somewhere.com');
        $this->batchMailHelper->addTo('somebodyelse@somewhere.com');
        $this->batchMailHelper->addTo('somebodyelse2@somewhere.com');
        $this->batchMailHelper->addTo('somebodyelse3@somewhere.com');
        $this->batchMailHelper->addTo('somebodyelse4@somewhere.com');
    }

    public function testQueueModeDisabledDoesNotThrowsExceptionWhenBatchLimitHit(): void
    {
        $this->mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                        ['mailer_spool_type', false, 'memory'],
                    ]
                )
            );

        // Enable queue mode
        try {
            $this->singleMailHelper->addTo('somebody@somewhere.com');
            $this->singleMailHelper->addTo('somebodyelse@somewhere.com');
        } catch (BatchQueueMaxException) {
            $this->fail('BatchQueueMaxException thrown');
        }

        // Otherwise success
        self::expectNotToPerformAssertions();
    }

    public function testQueuedEmailFromOverride(): void
    {
        $this->singleMailHelper->enableQueue();

        $email = new Email();
        $email->setFromAddress('override@nowhere.com');
        $email->setFromName('Test');

        $this->singleMailHelper->setEmail($email);

        foreach ($this->contacts as $contact) {
            $this->singleMailHelper->addTo($contact['email']);
            $this->singleMailHelper->setLead($contact);
            $this->singleMailHelper->queue();
        }

        $this->singleMailHelper->flushQueue();
        $from = $this->singleMailHelper->message->getFrom();

        $this->assertTrue(1 === count($from));
        $this->assertSame('override@nowhere.com', $from[0]->getAddress());

        $this->singleMailHelper->reset();
        foreach ($this->contacts as $contact) {
            $this->singleMailHelper->addTo($contact['email']);
            $this->singleMailHelper->setLead($contact);
            $this->singleMailHelper->queue();
        }
        $this->singleMailHelper->flushQueue();
        $from = $this->singleMailHelper->message->getFrom();

        $this->assertTrue(1 === count($from));
        $this->assertSame('nobody@nowhere.com', $from[0]->getAddress());
    }

    public function testBatchMode(): void
    {
        $this->singleMailHelper->enableQueue();

        $email = new Email();
        $email->setSubject('Hello');
        $this->singleMailHelper->setEmail($email);

        $this->singleMailHelper->addTo($this->contacts[0]['email']);
        $this->singleMailHelper->setLead($this->contacts[0]);
        $this->singleMailHelper->queue();
        $this->singleMailHelper->flushQueue();
        $errors = $this->singleMailHelper->getErrors();
        $this->assertTrue(empty($errors['failures']), var_export($errors, true));

        $this->singleMailHelper->reset(false);
        $this->singleMailHelper->setEmail($email);
        $this->singleMailHelper->addTo($this->contacts[1]['email']);
        $this->singleMailHelper->setLead($this->contacts[1]);
        $this->singleMailHelper->queue();
        $this->singleMailHelper->flushQueue();
        $errors = $this->singleMailHelper->getErrors();
        $this->assertTrue(empty($errors['failures']), var_export($errors, true));
    }

    public function testQueuedOwnerAsMailer(): void
    {
        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => null, 'signature' => 'owner 1'],
                ['email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => null, 'signature' => 'owner 2'],
            );
        $transport     = new BatchTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);

        $email = new Email();
        $email->setUseOwnerAsMailer(true);
        $email->setSubject('Subject');
        $email->setCustomHtml('content');

        $mailer->setEmail($email);
        $mailer->enableQueue();
        $this->fromEmailHelper->expects($this->exactly(4))
            ->method('getFromAddressArrayConsideringOwner')
            ->willReturnOnConsecutiveCalls(
                ['owner1@owner.com' => 'owner 1'],
                ['nobody@nowhere.com' => 'No Body'],
                ['owner2@owner.com'   => 'owner 2'],
                ['owner1@owner.com'   => 'owner 1']
            );
        $this->fromEmailHelper
            ->method('getSignature')
            ->willReturnOnConsecutiveCalls(
                'owner 1',
                '',
                'owner 2',
                'owner 1'
            );

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        $this->assertTrue(empty($mailer->getErrors()['failures']));

        $fromAddresses = $transport->getFromAddresses();
        $metadatas     = $transport->getMetadatas();

        $this->assertEquals(3, count($fromAddresses));
        $this->assertEquals(3, count($metadatas));
        $this->assertEquals(['owner1@owner.com', 'nobody@nowhere.com', 'owner2@owner.com'], $fromAddresses);

        foreach ($metadatas as $key => $metadata) {
            $this->assertTrue(isset($metadata[$this->contacts[$key]['email']]));

            if (0 === $key) {
                // Should have two contacts
                $this->assertEquals(2, count($metadata));
                $this->assertTrue(isset($metadata['contact4@somewhere.com']));
            } else {
                $this->assertEquals(1, count($metadata));
            }

            // Check that signatures are valid
            if (1 === $key) {
                // signature should be empty
                $this->assertEquals('', $metadata['contact2@somewhere.com']['tokens']['{signature}']);
            } else {
                $this->assertEquals($metadata[$this->contacts[$key]['email']]['tokens']['{signature}'], 'owner '.$this->contacts[$key]['owner_id']);

                if (0 === $key) {
                    // Ensure the last contact has the correct signature
                    $this->assertEquals($metadata['contact4@somewhere.com']['tokens']['{signature}'], 'owner '.$this->contacts[$key]['owner_id']);
                }
            }
        }

        // Validate that the message object only has the contacts for the last "from" group to ensure we aren't sending duplicates
        $this->assertEquals('contact3@somewhere.com', $mailer->message->getTo()[0]->getAddress());
    }

    public function testMailAsOwnerWithEncodedCharactersInName(): void
    {
        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );
        $transport     = new BatchTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $email  = new Email();
        $email->setUseOwnerAsMailer(true);

        $mailer->setEmail($email);
        $mailer->enableQueue();
        $mailer->setSubject('Hello');

        $contacts = $this->contacts;
        foreach ($contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        $fromAddresses = $transport->getFromAddresses();
        $fromNames     = $transport->getFromNames();

        $this->assertEquals(3, count($fromAddresses)); // 3, not 4, because the last contact has the same owner as the first contact.
        $this->assertEquals(3, count($fromNames));
        $this->assertEquals(['owner1@owner.com', 'nobody@nowhere.com', 'owner2@owner.com'], $fromAddresses);
        $this->assertEquals(['owner 1', 'No Body\'s Business', 'owner 2'], $fromNames);
    }

    public function testBatchIsEnabledWithBcTokenInterface(): void
    {
        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );
        $transport     = new BcInterfaceTokenTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $email  = new Email();
        $this->fromEmailHelper->expects($this->exactly(4))
            ->method('getFromAddressConsideringOwner')
            ->willReturnOnConsecutiveCalls(
                ['owner1@owner.com' => null],
                ['nobody@nowhere.com' => null],
                ['owner2@owner.com'   => null],
                ['nobody@nowhere.com' => null]
            );

        $email->setUseOwnerAsMailer(true);

        $mailer->setEmail($email);

        $mailer->enableQueue();

        $mailer->setSubject('Hello');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $mailer->flushQueue([]);

        $this->assertEmpty($mailer->getErrors()['failures']);

        $fromAddresses = $transport->getFromAddresses();
        $metadatas     = $transport->getMetadatas();

        $this->assertEquals(3, count($fromAddresses));
        $this->assertEquals(3, count($metadatas));
    }

    public function testGlobalFromThatAllFromAddressesAreTheSame(): void
    {
        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );

        $transport     = new BcInterfaceTokenTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $mailer->enableQueue();
        $mailer->setSubject('Hello');
        $mailer->setFrom('override@owner.com');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->queue();
        }

        $this->assertEmpty($mailer->getErrors());

        $fromAddresses = $transport->getFromAddresses();

        $this->assertEquals(['override@owner.com'], array_unique($fromAddresses));
    }

    public function testStandardEmailFrom(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $email         = new Email();

        $email->setUseOwnerAsMailer(false);
        $email->setFromAddress('override@nowhere.com');
        $email->setFromName('Test');
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->setBody('{signature}');
            $mailer->send();
            $address = $mailer->message->getFrom() ? $mailer->message->getFrom()[0]->getAddress() : null;
            $this->assertEquals('override@nowhere.com', $address);
        }
    }

    public function testStandardEmailReplyTo(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $email         = new Email();

        $email->setSubject('Subject');
        $email->setCustomHtml('content');

        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo() ? $mailer->message->getReplyTo()[0]->getAddress() : null;
        $this->assertEquals('nobody@nowhere.com', $replyTo);

        $email->setReplyToAddress('replytooverride@nowhere.com');
        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo() ? $mailer->message->getReplyTo()[0]->getAddress() : null;
        $this->assertEquals('replytooverride@nowhere.com', $replyTo);
    }

    public function testEmailReplyToWithFromEmail(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $email         = new Email();

        // From address is set
        $email->setFromAddress('from@nowhere.com');
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo() ? $mailer->message->getReplyTo()[0]->getAddress() : null;
        // Expect from address in reply to
        $this->assertEquals('from@nowhere.com', $replyTo);
    }

    public function testEmailReplyToWithFromAndGlobalEmail(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $email         = new Email();

        // From address is set
        $email->setFromAddress('from@nowhere.com');
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);
        $mailer->send();
        $replyTo = $mailer->message->getReplyTo() ? $mailer->message->getReplyTo()[0]->getAddress() : null;
        // Expect from address in reply to
        $this->assertEquals('admin@mautic.com', $replyTo);
    }

    public function testStandardOwnerAsMailer(): void
    {
        $this->contactRepository->method('getLeadOwner')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => 'owner 1', 'last_name' => '', 'signature' => 'owner 1'],
                ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => 'owner 2', 'last_name' => '', 'signature' => 'owner 2'],
            );

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);

        $email = new Email();
        $email->setUseOwnerAsMailer(true);
        $email->setSubject('Subject');
        $email->setCustomHtml('content');
        $mailer->setEmail($email);

        $mailer->setBody('{signature}');

        foreach ($this->contacts as $contact) {
            $mailer->addTo($contact['email']);
            $mailer->setLead($contact);
            $mailer->send();

            $body = $mailer->message->getHtmlBody();
            $from = $mailer->message->getFrom() ? $mailer->message->getFrom()[0]->getAddress() : null;

            if ($contact['owner_id']) {
                $this->assertEquals('owner'.$contact['owner_id'].'@owner.com', $from);
                $this->assertEquals('owner '.$contact['owner_id'], $body);
            } else {
                $this->assertEquals('nobody@nowhere.com', $from);
                $this->assertEquals('', $body);
            }
        }
    }

    /**
     * @dataProvider provideEmails
     */
    public function testValidateEmails(string $email, bool $isValid): void
    {
        $helper = $this->mockEmptyMailHelper();
        if (!$isValid) {
            $this->expectException(InvalidEmailException::class);
        }
        $this->assertNull($helper::validateEmail($email)); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateValidEmails(): void
    {
        $helper    = $this->mockEmptyMailHelper();
        $addresses = [
            'john@doe.com',
            'john@doe.email',
            'john.doe@email.com',
            'john+doe@email.com',
            'john@doe.whatevertldtheycomewithinthefuture',
        ];

        foreach ($addresses as $address) {
            // will throw InvalidEmailException if it will find the address invalid
            $this->assertNull($helper::validateEmail($address)); /** @phpstan-ignore-line as it's testing a deprecated method */
        }
    }

    /**
     * @return mixed[]
     */
    public function provideEmails(): array
    {
        return [
            ['john@doe.com', true],
            ['john@doe.email', true],
            ['john@doe.whatevertldtheycomewithinthefuture', true],
            ['john.doe@email.com', true],
            ['john+doe@email.com', true],
            ['john@doe', false],
            ['jo hn@doe.email', false],
            ['jo^hn@doe.email', false],
            ['jo\'hn@doe.email', false],
            ['jo;hn@doe.email', false],
            ['jo&hn@doe.email', false],
            ['jo*hn@doe.email', false],
            ['jo%hn@doe.email', false],
        ];
    }

    public function testValidateEmailWithoutTld(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('john@doe'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithSpaceInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithCaretInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo^hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithApostropheInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo\'hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithSemicolonInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo;hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithAmpersandInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo&hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithStarInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo*hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testValidateEmailWithPercentInIt(): void
    {
        $helper = $this->mockEmptyMailHelper();
        $this->expectException(InvalidEmailException::class);
        $helper::validateEmail('jo%hn@doe.email'); /** @phpstan-ignore-line as it's testing a deprecated method */
    }

    public function testGlobalHeadersAreSet(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $mailer->setBody('{signature}');
        $mailer->addTo($this->contacts[0]['email']);
        $mailer->send();

        $customHeadersFounds = [];

        /** @var array<\Symfony\Component\Mime\Header\AbstractHeader> $headers */
        $headers = $mailer->message->getHeaders()->all();
        foreach ($headers as $header) {
            if (str_contains($header->getName(), 'X-Mautic-Test')) {
                $customHeadersFounds[] = $header->getName();

                $this->assertEquals('test', $header->getBody());
            }
        }

        $this->assertCount(2, $customHeadersFounds);
    }

    public function testGlobalHeadersAreMergedIfEmailEntityIsSet(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('{signature}');
        $mailer->setEmail($email);
        $mailer->send();

        /** @var array<HeaderInterface> $headers */
        $headers = iterator_to_array($mailer->message->getHeaders()->all());

        foreach ($headers as $header) {
            if (str_contains($header->getName(), 'X-Mautic-Test')) {
                $this->assertEquals('test', $header->getBody());
            }
        }

        $this->assertSame('test', $headers['x-mautic-test']->getBody());
        $this->assertSame('test', $headers['x-mautic-test2']->getBody());
    }

    public function testEmailHeadersAreSet(): void
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('{signature}');
        $email->setHeaders(['X-Mautic-Test3' => 'test2', 'X-Mautic-Test4' => 'test2']);
        $mailer->setEmail($email);
        $mailer->send();

        $customHeadersFounds = [];

        /** @var array<\Symfony\Component\Mime\Header\AbstractHeader> $headers */
        $headers = $mailer->message->getHeaders()->all();
        foreach ($headers as $header) {
            if ('X-Mautic-Test' === $header->getName() || 'X-Mautic-Test2' === $header->getName()) {
                $customHeadersFounds[] = $header->getName();
                $this->assertEquals('test', $header->getBody());
            }
            if ('X-Mautic-Test3' === $header->getName() || 'X-Mautic-Test4' === $header->getName()) {
                $customHeadersFounds[] = $header->getName();
                $this->assertEquals('test2', $header->getBody());
            }
        }

        $this->assertCount(4, $customHeadersFounds);
    }

    public function testUnsubscribeHeader(): void
    {
        $mockRouter  = $this->createMock(Router::class);
        $mockRouter->expects($this->once())
            ->method('generate')
            ->with('mautic_email_unsubscribe',
                ['idHash' => 'hash'],
                UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.com/email/unsubscribe/65842d012b5b5772172137');

        $parameterMap = [
            ['mailer_custom_headers', [], ['X-Mautic-Test' => 'test', 'X-Mautic-Test2' => 'test']],
        ];

        /** @var MockObject|MauticFactory $mockFactory */
        $mockFactory = $this->getMockFactory(true, $parameterMap);

        $mockFactory->method('getRouter')
            ->willReturnOnConsecutiveCalls($mockRouter);

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('<html></html>');

        $mailer = new MailHelper($mockFactory, $symfonyMailer, ['nobody@nowhere.com' => 'No Body']);
        $mailer->setIdHash('hash');
        $mailer->setEmail($email);
        $lead = new Lead();
        $lead->setEmail('someemail@email.test');
        $mailer->setLead($lead);

        $mailer->setEmailType(MailHelper::EMAIL_TYPE_MARKETING);
        $headers = $mailer->getCustomHeaders();
        $this->assertSame('<https://example.com/email/unsubscribe/65842d012b5b5772172137>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

        // There are no unsubscribe headers in transactional emails.
        $mailer->setEmailType(MailHelper::EMAIL_TYPE_TRANSACTIONAL);
        $headers = $mailer->getCustomHeaders();
        $this->assertNull($headers['List-Unsubscribe'] ?? null);
        $this->assertNull($headers['List-Unsubscribe-Post'] ?? null);
    }

    protected function mockEmptyMailHelper(): MailHelper
    {
        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);

        return new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);
    }

    /**
     * @param mixed[] $parameterMap
     *
     * @phpstan-ignore-next-line as it's using a deprecated class
     *
     * @return MauticFactory&MockObject
     */
    protected function getMockFactory(bool $mailIsOwner = true, array $parameterMap = []): MauticFactory
    {
        $mockLeadRepository = $this->createMock(LeadRepository::class);

        $mockLeadRepository->method('getLeadOwner')
            ->will(
                $this->returnValueMap(
                    [
                        [1, ['id' => 1, 'email' => 'owner1@owner.com', 'first_name' => '', 'last_name' => '', 'signature' => 'owner 1']],
                        [2, ['id' => 2, 'email' => 'owner2@owner.com', 'first_name' => '', 'last_name' => '', 'signature' => 'owner 2']],
                        [3, ['id' => 3, 'email' => 'owner3@owner.com', 'first_name' => 'John', 'last_name' => 'S&#39;mith', 'signature' => 'owner 2']],
                    ]
                )
            );

        $mockLeadModel = $this->createMock(LeadModel::class);

        $mockLeadModel->method('getRepository')->willReturn($mockLeadRepository);

        $mockFactory = $this->mockFactory;

        $parameterMap = array_merge(
            [
                ['mailer_return_path', false, null],
                ['mailer_is_owner', false, $mailIsOwner],
            ],
            $parameterMap
        );

        $fromEmailHelper = new FromEmailHelper($this->createMock(CoreParametersHelper::class), $mockLeadRepository);

        $mockFactory->method('getParameter')->will($this->returnValueMap($parameterMap));
        $mockFactory->method('getModel')->will($this->returnValueMap([['lead', $mockLeadModel]]));
        $mockFactory->method('get')->will($this->returnValueMap([['mautic.helper.from_email_helper', $fromEmailHelper]]));

        $mockLogger = $this->getMockBuilder(Logger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockFactory->method('getLogger')
            ->willReturn($mockLogger);

        $mockMailboxHelper = $this->getMockBuilder(Mailbox::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockMailboxHelper->method('isConfigured')
            ->willReturn(false);

        $mockFactory->method('getHelper')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailbox', $mockMailboxHelper],
                    ]
                )
            );

        return $mockFactory;
    }

    public function testArrayOfAddressesAreRemappedIntoEmailToNameKeyValuePair(): void
    {
        $mockFactory = $this->mockFactory;
        $mockFactory->method('getParameter')
            ->will(
                $this->returnValueMap(
                    [
                        ['mailer_return_path', false, null],
                    ]
                )
            );
        $mockLogger = $this->createMock(Logger::class);
        $mockFactory->method('getLogger')->willReturn($mockLogger);

        $symfonyMailer = new Mailer(new SmtpTransport());
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);

        $mailer->setTo(['sombody@somewhere.com', 'sombodyelse@somewhere.com'], 'test');

        $emailsTo = [];

        foreach ($mailer->message->getTo() as $address) {
            $emailsTo[$address->getAddress()] = $address->getName();
        }
        $this->assertEquals(
            [
                'sombody@somewhere.com'     => 'test',
                'sombodyelse@somewhere.com' => 'test',
            ],
            $emailsTo
        );
    }

    /**
     * @dataProvider minifyHtmlDataProvider
     */
    public function testMinifyHtml(bool $minifyHtml, string $html, string $expectedHtml): void
    {
        $params = [
            ['mailer_from_email', null, 'nobody@nowhere.com'],
        ];
        $params[] = ['minify_email_html', null, $minifyHtml];
        $params[] = ['mailer_is_owner', null, false];
        $params[] = ['mailer_append_tracking_pixel', null, false];
        $this->coreParametersHelper->method('get')->will($this->returnValueMap($params));
        $symfonyMailer = new Mailer(new SmtpTransport());
        $mailer        = new MailHelper($this->mockFactory, $symfonyMailer, $this->fromEmailHelper, $this->coreParametersHelper, $this->mailbox);

        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setCustomHtml($html);
        $email->setSubject('Subject');
        $mailer->setEmail($email);
        $this->assertSame($expectedHtml, $mailer->getBody(), $mailer->getBody());
    }

    /**
     * @return array<array<bool|int|string>>
     */
    public static function minifyHtmlDataProvider(): array
    {
        return [
            [false, self::MINIFY_HTML, self::MINIFY_HTML],
            [true, self::MINIFY_HTML, InputHelper::minifyHTML(self::MINIFY_HTML)],
        ];
    }

    public function testHeadersAreTokenized(): void
    {
        $parameterMap = [
          ['mailer_custom_headers', [], ['X-Mautic-Test-1' => '{tracking_pixel}']],
        ];
        $mockFactory = $this->getMockFactory(true, $parameterMap);

        $transport     = new SmtpTransport();
        $symfonyMailer = new Mailer($transport);

        $mailer = new MailHelper($mockFactory, $symfonyMailer, ['nobody@nowhere.com' => '{tracking_pixel}']);
        $mailer->addTo($this->contacts[0]['email']);

        $email = new Email();
        $email->setSubject('Test');
        $email->setCustomHtml('content');
        $email->setHeaders(['X-Mautic-Test-2' => '{tracking_pixel}']);
        $mailer->setEmail($email);
        $mailer->send();

        /** @var array<\Symfony\Component\Mime\Header\AbstractHeader> $headers */
        $headers = $mailer->message->getHeaders()->all();

        foreach ($headers as $header) {
            if ('X-Mautic-Test-1' === $header->getName() || 'X-Mautic-Test-2' === $header->getName()) {
                $this->assertEquals(MailHelper::getBlankPixel(), $header->getBody());
            } elseif ('from' === $header->getName()) {
                $this->assertEquals('{tracking_pixel}', $header->getBody()->getName());
            }
        }
    }
}
