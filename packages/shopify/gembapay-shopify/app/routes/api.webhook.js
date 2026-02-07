import { json } from "@remix-run/node";
import { getClient, getSettings } from "../lib/gembapay.server";

/**
 * POST /api/webhook
 * Receives payment notifications from GembaPay
 */
export const action = async ({ request }) => {
  const payload = await request.text();
  const signature = request.headers.get("x-gembapay-signature") || "";

  // Parse the payment data to find the shop
  let data;
  try {
    data = JSON.parse(payload);
  } catch {
    return json({ error: "Invalid payload" }, { status: 400 });
  }

  const { event, payment, testMode } = data;
  const orderId = payment?.orderId;

  if (!orderId) {
    return json({ error: "Missing orderId" }, { status: 400 });
  }

  // Extract shop from order (SHOPIFY-{shopify_order_id})
  // In production, look up the shop from a database using the order ID
  console.log(`[GembaPay Webhook] ${event} | Order: ${orderId} | Test: ${testMode || false}`);

  if (event === "payment.completed") {
    console.log(`  Method: ${payment.network}`);
    console.log(`  Amount: $${payment.usdAmount} USD`);
    if (payment.txHash) {
      console.log(`  TX: ${payment.txHash}`);
    }

    // In production:
    // 1. Look up the Shopify order
    // 2. Mark it as paid via Shopify Admin API
    // 3. Trigger fulfillment if needed
    //
    // const admin = await getShopifyAdmin(shop);
    // await admin.graphql(`mutation { orderMarkAsPaid(input: { id: "${orderId}" }) { ... } }`);
  }

  return json({ received: true });
};
