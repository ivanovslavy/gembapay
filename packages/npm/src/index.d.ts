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
    /** Currency code — 86+ supported (default: 'USD') */
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
    status: 'pending' | 'processing' | 'confirmed' | 'completed' | 'failed' | 'expired';
    amountUsd: string;
    network: 'stripe' | 'paypal';
    paymentProvider: 'stripe' | 'paypal';
    payment?: {
      txHash?: string;
      tokenSymbol?: string;
      tokenAmount?: string;
    };
  }

  export interface WebhookEvent {
    event: 'payment.completed' | 'payment.failed';
    testMode?: boolean;
    payment: {
      orderId: string;
      txHash?: string;
      network: string;
      usdAmount: number;
      tokenAmount?: string;
      paymentProvider?: string;
    };
    timestamp: string;
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

    /** Verify webhook signature */
    verifyWebhook(payload: string | Buffer | object, signature: string): boolean;

    /** Parse and verify a webhook request */
    parseWebhook(req: { headers: Record<string, string>; body: any }): WebhookEvent;

    /** Express middleware for webhook handling */
    webhookHandler(handler: (event: WebhookEvent) => Promise<void>): (req: any, res: any) => Promise<void>;
  }

  export default GembaPay;
}
