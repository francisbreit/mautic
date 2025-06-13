<?php

namespace Mautic\SmsBundle\Integration\Twilio;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\SmsBundle\Sms\TransportInterface;
use Psr\Log\LoggerInterface;

class TwilioTransport implements TransportInterface
{
    public function __construct(
        private Configuration $configuration,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param Lead $lead
     * @param string $content
     *
     * @return bool|string
     */
    public function sendSms(Lead $lead, $content)
    {
        $number = $lead->getLeadPhoneNumber();

        if (null === $number) {
            return false;
        }

        try {
            // Configurando a URL do webhook e o valor do cabeçalho
           $webhookUrl = trim((string) $this->configuration->getAccountSid());
           $authToken = trim((string) $this->configuration->getAuthToken());

           

            // Garantindo que o authToken seja uma string
            if (!is_string($authToken)) {
                $authToken = json_encode($authToken);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $authToken = '';
                }
            }

            // Dados a serem enviados no corpo da requisição
            $data = [
                'to' => $this->sanitizeNumber($number),
                'message' => $content,
            ];

            // Validar JSON
            $jsonPayload = json_encode($data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Erro ao codificar JSON: ' . json_last_error_msg());
            }

            // Configurando cURL para enviar ao webhook
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'dados_extras: ' . $authToken, // Cabeçalho adicional
     
            ]);

            $this->logger->info('Enviando dados para o webhook', ['url' => $webhookUrl, 'payload' => $data]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new \Exception("Erro cURL: $error");
            }

            curl_close($ch);

            // Verifica se houve erro na resposta do webhook
            if ($httpCode !== 200) {
                throw new \Exception("Webhook respondeu com código HTTP $httpCode");
            }

            return true;
        } catch (NumberParseException $numberParseException) {
            $this->logger->warning(
                $numberParseException->getMessage(),
                ['exception' => $numberParseException]
            );

            return $numberParseException->getMessage();
        } catch (\Exception $exception) {
            $this->logger->warning(
                $exception->getMessage(),
                ['exception' => $exception]
            );

            return $exception->getMessage();
        }
    }

    /**
     * @param string $number
     *
     * @return string
     *
     * @throws NumberParseException
     */
    private function sanitizeNumber($number)
    {
        $util = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($number, 'US');

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
