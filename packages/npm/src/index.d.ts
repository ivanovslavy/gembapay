declare module 'gembapay' {
  export interface GembaPayOptions {
    /** API key (gembapay_test_... or gembapay_live_...) */
    apiKey: string;
    /** Webhook signing secret */
    webhookSecret?: string;
    /** Custom base URL */
    baseUrl?: string;
    /** Request timeout in ms (default: 30000) */
    timeout?: number;
  }

  export interface CreatePaymentParams {
    /** Your unique order identifier */
    orderId: string;
    /** Payment amount */
    amount: number;
    /** Currency code — 51+ supported (default: 'USD') */
    currency?: string;
    /** Payment description */
    description?: string;
  }

  export interface PaymentResponse {
    success: boolean;
    orderId: string;
    paymentUrl: string;
    amountUsd: string;
    amountOriginal: number;
    currencyOriginal: string;
    allowedMethods: ('stripe' | 'paypal')[];
    expiresAt: string;
  }

  export interface PaymentStatus {
    orderId: string;
    status: 'pending' | 'processing' | 'confirmed' | 'completed' | 'failed' | 'expired' | 'refunded';
    amountUsd: string;
    network: 'stripe' | 'paypal';
    paymentProvider: 'stripe' | 'paypal';
    payment?: {
      reference?: string;
      tokenSymbol?: string;
      tokenAmount?: string;
    };
  }

  /** Stripe may add other values over time - treat unknown ones like 'card'. */
  export type PaymentMethod = 'card' | 'apple_pay' | 'google_pay' | 'link' | (string & {});

  export type WebhookEventType =
    | 'payment.completed' | 'payment.refunded' | 'payment.failed' | 'payment.expired'
    | 'dispute.created' | 'dispute.closed'
    | 'subscription.activated' | 'subscription.payment' | 'subscription.payment_failed' | 'subscription.canceled';

  /** Fields every webhook carries. */
  interface WebhookEnvelope {
    /** Unique per event — use it as your idempotency key. */
    eventId: string;
    /** true for test-mode payments (test key, test cards, PayPal sandbox) */
    testMode?: boolean;
    /** true only for dashboard "Send test" samples — never act on them */
    test?: boolean;
    timestamp: string;
  }

  export interface PaymentCompletedEvent extends WebhookEnvelope {
    event: 'payment.completed';
    payment: {
      orderId: string;
      amount: number;
      currency: string;
      amountOriginal?: number | null;
      currencyOriginal?: string | null;
      usdAmount: number;
      exchangeRate?: number | null;
      status: 'completed';
      /** Provider payment id: Stripe PaymentIntent (pi_…) or PayPal capture id */
      txHash: string;
      network: string;
      paymentProvider: 'stripe' | 'paypal' | string;
      /** How the customer paid. null for PayPal and payments recorded before 2026-10-21. */
      paymentMethod: PaymentMethod | null;
      customerAddress?: string;
      timestamp?: string;
    };
  }

  export interface Refund {
    id: string;
    orderId: string;
    amount: number;
    currency: string;
    status: 'succeeded' | 'pending' | 'failed';
    reason: string | null;
    source: 'api' | 'dashboard' | 'provider';
    provider: 'stripe' | 'paypal';
    providerRefundId: string | null;
    failureReason: string | null;
    testMode: boolean;
    createdAt: string;
  }

  interface PaymentSummary {
    orderId: string;
    amount: number;
    currency: string;
    paymentProvider: 'stripe' | 'paypal' | string;
    paymentMethod: PaymentMethod | null;
    /** Provider payment id (pi_… / PayPal capture id) */
    reference: string;
  }

  export interface PaymentRefundedEvent extends WebhookEnvelope {
    event: 'payment.refunded';
    refund: Refund;
    payment: PaymentSummary & { refundedTotal: number; remaining: number; fullyRefunded: boolean };
  }

  export interface PaymentFailedEvent extends WebhookEnvelope {
    event: 'payment.failed';
    payment: {
      orderId: string;
      amount: number;
      currency: string;
      paymentProvider: 'stripe' | 'paypal' | string;
      paymentMethod: PaymentMethod | null;
      failureCode: string | null;
      failureMessage: string | null;
      /** Always false: the customer may retry until expiresAt */
      final: false;
      expiresAt: string | null;
    };
  }

  export interface PaymentExpiredEvent extends WebhookEnvelope {
    event: 'payment.expired';
    payment: { orderId: string; amount: number; currency: string; status: 'expired'; expiresAt: string };
  }

  export interface Dispute {
    id: string;
    provider: 'stripe' | 'paypal';
    providerDisputeId: string;
    orderId: string;
    amount: number | null;
    currency: string | null;
    reason: string | null;
    status: string;
    outcome: 'won' | 'lost' | 'other' | null;
    createdAt: string;
    closedAt: string | null;
  }

  export interface DisputeEvent extends WebhookEnvelope {
    event: 'dispute.created' | 'dispute.closed';
    dispute: Dispute;
    payment: PaymentSummary;
  }

  /** Subscription events use a flat payload (no `payment`, no orderId). */
  export interface SubscriptionEvent {
    event: 'subscription.activated' | 'subscription.payment' | 'subscription.payment_failed' | 'subscription.canceled';
    eventId: string;
    subscriptionId: string;
    providerSubscriptionId: string | null;
    planId: string | null;
    planToken: string | null;
    customerEmail: string | null;
    status: string | null;
    isTestMode: boolean;
    amount?: number;
    currency?: string;
    paymentMethod?: PaymentMethod | null;
    test?: boolean;
    timestamp: string;
  }

  export type WebhookEvent =
    | PaymentCompletedEvent | PaymentRefundedEvent | PaymentFailedEvent | PaymentExpiredEvent
    | DisputeEvent | SubscriptionEvent;

  export interface CreateRefundParams {
    orderId: string;
    /** In the payment currency; omit to refund everything still refundable */
    amount?: number | string;
    reason?: string;
  }

  export interface IdempotencyOptions {
    /** Reuse the same key when retrying after a timeout so the operation cannot happen twice. */
    idempotencyKey?: string;
  }

  export interface CustomerField { enabled: boolean; required: boolean }

  export interface CreatePaymentLinkParams {
    /** Fixed amount; omit/null to let the payer choose */
    amount?: number | null;
    currency?: string;
    description?: string;
    methods?: ('stripe' | 'paypal')[];
    multiUse?: boolean;
    maxUses?: number | null;
    maxTotalAmount?: number | null;
    expiresAt?: string | null;
    customerFields?: Partial<Record<'name' | 'email' | 'phone' | 'note', CustomerField>>;
    merchantNotify?: boolean;
  }

  export interface PaymentLink {
    id: string;
    token: string;
    amount: number | null;
    openAmount: boolean;
    currency: string;
    description: string | null;
    methods: string[];
    multiUse: boolean;
    maxUses: number | null;
    maxTotalAmount: number | null;
    expiresAt: string | null;
    merchantNotify: boolean;
    isTestMode: boolean;
    mode: 'test' | 'live';
    status: string;
    rawStatus: 'active' | 'disabled';
    usedCount: number;
    totalCollected: number;
    createdAt: string;
    uses?: any[];
    [key: string]: any;
  }

  export interface StatsResponse {
    [key: string]: any;
  }

  export interface TransactionsResponse {
    [key: string]: any;
  }

  export class GembaPayError extends Error {
    statusCode: number | null;
    code: string | null;
    constructor(message: string, statusCode?: number | null, code?: string | null);
  }

  export class GembaPay {
    /** Whether using test mode (determined by API key prefix) */
    readonly isTestMode: boolean;

    constructor(options: GembaPayOptions);

    /** Create a payment request */
    createPayment(params: CreatePaymentParams): Promise<PaymentResponse>;

    /** Get payment details */
    getPayment(orderId: string): Promise<any>;

    /** Check payment status */
    getPaymentStatus(orderId: string): Promise<PaymentStatus>;

    /** List merchant transactions */
    listTransactions(params?: Record<string, string>): Promise<TransactionsResponse>;

    /** Get merchant statistics */
    getStats(): Promise<StatsResponse>;

    /** Refund a payment fully or partly (key needs "refunds:write") */
    createRefund(params: CreateRefundParams, options?: IdempotencyOptions): Promise<Refund>;
    listRefunds(params?: { orderId?: string; limit?: number }): Promise<Refund[]>;
    getRefund(refundId: string): Promise<Refund>;

    /** Payment links (key needs "payment_links:write" to create/change) */
    createPaymentLink(params: CreatePaymentLinkParams, options?: IdempotencyOptions): Promise<PaymentLink>;
    listPaymentLinks(): Promise<PaymentLink[]>;
    getPaymentLink(id: string): Promise<PaymentLink>;
    setPaymentLinkStatus(id: string, status: 'active' | 'disabled'): Promise<PaymentLink>;
    deletePaymentLink(id: string): Promise<{ success: boolean; ok: boolean }>;

    /** Verify the X-GembaPay-Signature-V2 header (timestamped, recommended) */
    verifyWebhookV2(rawBody: string | Buffer, header: string, options?: { toleranceSec?: number }): boolean;

    /** Verify the legacy X-GembaPay-Signature header (no replay protection) */
    verifyWebhook(payload: string | Buffer | object, signature: string): boolean;

    /** Parse and verify a webhook request — uses Signature-V2 when present */
    parseWebhook(req: { headers: Record<string, string | undefined>; body: any; rawBody?: any }, options?: { requireV2?: boolean }): WebhookEvent;

    /** Express middleware for webhook handling (mount with express.raw) */
    webhookHandler(handler: (event: WebhookEvent) => Promise<void>, options?: { requireV2?: boolean }): (req: any, res: any) => Promise<void>;
  }

  export default GembaPay;
}
