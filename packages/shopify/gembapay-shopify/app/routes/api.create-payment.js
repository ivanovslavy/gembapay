import { json } from "@remix-run/node";
import { authenticate } from "../shopify.server";
import { getClient } from "../lib/gembapay.server";

/**
 * POST /api/create-payment
 * Called when a customer selects GembaPay at checkout
 */
export const action = async ({ request }) => {
  const { session } = await authenticate.admin(request);
  const client = getClient(session.shop);

  if (!client) {
    return json({ error: "GembaPay not configured" }, { status: 400 });
  }

  try {
    const { orderId, amount, currency } = await request.json();

    const payment = await client.createPayment({
      orderId: `SHOPIFY-${orderId}`,
      amount: parseFloat(amount),
      currency: currency || "USD",
      description: `Order from ${session.shop}`,
    });

    return json({
      success: true,
      paymentUrl: payment.paymentUrl,
      allowedMethods: payment.allowedMethods,
    });
  } catch (error) {
    return json({ error: error.message }, { status: 500 });
  }
};
