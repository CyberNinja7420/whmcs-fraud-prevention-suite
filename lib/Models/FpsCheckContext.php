<?php
declare(strict_types=1);

namespace FraudPreventionSuite\Lib\Models;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


/**
 * FpsCheckContext -- immutable data object carrying all input data for a fraud check.
 *
 * Built once per check invocation and passed to every provider and rule evaluator
 * so they all operate on the same snapshot of client/order data.
 */
class FpsCheckContext
{
    public readonly string $email;
    public readonly string $ip;
    public readonly string $phone;
    public readonly string $country;
    public readonly int $orderId;
    public readonly int $clientId;
    public readonly float $amount;
    public readonly string $domain;
    public readonly string $fingerprintHash;
    public readonly string $checkType;
    public readonly array $meta;

    public function __construct(
        string $email = '',
        string $ip = '',
        string $phone = '',
        string $country = '',
        int $orderId = 0,
        int $clientId = 0,
        float $amount = 0.0,
        string $domain = '',
        string $fingerprintHash = '',
        string $checkType = 'auto',
        array $meta = []
    ) {
        $this->email           = strtolower(trim($email));
        $this->ip              = trim($ip);
        $this->phone           = trim($phone);
        $this->country         = strtoupper(trim($country));
        $this->orderId         = $orderId;
        $this->clientId        = $clientId;
        $this->amount          = $amount;
        $this->domain          = strtolower(trim($domain));
        $this->fingerprintHash = trim($fingerprintHash);
        $this->checkType       = $checkType;
        $this->meta            = $meta;
    }

    /**
     * Extract the domain part from the email address.
     */
    public function getEmailDomain(): string
    {
        $parts = explode('@', $this->email);
        return $parts[1] ?? '';
    }

    /**
     * Build a context from a WHMCS order + client record.
     *
     * @param object $order  Row from tblorders
     * @param object $client Row from tblclients
     */
    public static function fromOrderAndClient(
        object $order,
        object $client,
        string $checkType = 'auto',
        string $fingerprintHash = '',
        array $meta = []
    ): self {
        $email  = $client->email ?? '';
        $domain = '';
        if ($email !== '') {
            $parts  = explode('@', $email);
            $domain = $parts[1] ?? '';
        }

        return new self(
            email:           $email,
            ip:              $order->ipaddress ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            phone:           $client->phonenumber ?? '',
            country:         $client->country ?? '',
            orderId:         (int) ($order->id ?? 0),
            clientId:        (int) ($client->id ?? 0),
            amount:          (float) ($order->amount ?? 0.0),
            domain:          $domain,
            fingerprintHash: $fingerprintHash,
            checkType:       $checkType,
            meta:            $meta,
        );
    }

    /**
     * Build a context for a manual client-level check (no specific order).
     */
    public static function fromClientId(int $clientId, string $checkType = 'manual'): self
    {
        try {
            $client = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first();

            if (!$client) {
                return new self(clientId: $clientId, checkType: $checkType);
            }

            $email  = $client->email ?? '';
            $domain = '';
            if ($email !== '') {
                $parts  = explode('@', $email);
                $domain = $parts[1] ?? '';
            }

            return new self(
                email:     $email,
                ip:        $client->ip ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
                phone:     $client->phonenumber ?? '',
                country:   $client->country ?? '',
                orderId:   0,
                clientId:  $clientId,
                amount:    0.0,
                domain:    $domain,
                checkType: $checkType,
            );
        } catch (\Throwable $e) {
            return new self(clientId: $clientId, checkType: $checkType);
        }
    }

    /**
     * Serialize to array for storage/logging.
     */
    public function toArray(): array
    {
        return [
            'email'            => $this->email,
            'ip'               => $this->ip,
            'phone'            => $this->phone,
            'country'          => $this->country,
            'order_id'         => $this->orderId,
            'client_id'        => $this->clientId,
            'amount'           => $this->amount,
            'domain'           => $this->domain,
            'fingerprint_hash' => $this->fingerprintHash,
            'check_type'       => $this->checkType,
        ];
    }
}
