/**
 * Called when GembaPay confirms payment via webhook
 */
export const resolve = async ({ sessionToken, paymentSession }) => {
  // Payment has been confirmed by GembaPay webhook
  return {
    status: "resolved",
  };
};
