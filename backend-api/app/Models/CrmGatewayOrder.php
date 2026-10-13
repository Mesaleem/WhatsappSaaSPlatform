<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CRM Sales Phase 3 — one online payment attempt for an invoice through the tenant's own
 * gateway: created -> paid | failed | refunded. Written by CrmGatewayService only; read
 * here so staff can see the attempts (reconciliation) on the invoice.
 */
class CrmGatewayOrder extends Model
{
    protected $table = 'crm_gateway_orders';

    protected $fillable = ['account_id', 'crm_document_id', 'gateway', 'gateway_order_id', 'amount', 'currency', 'status', 'crm_payment_id'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'crm_document_id' => 'integer', 'crm_payment_id' => 'integer', 'amount' => 'decimal:2'];
    }
}
