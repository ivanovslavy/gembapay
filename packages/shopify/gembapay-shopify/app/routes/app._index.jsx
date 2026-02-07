import { json } from "@remix-run/node";
import { useLoaderData, useSubmit, useNavigation } from "@remix-run/react";
import { useState, useCallback } from "react";
import {
  Page,
  Layout,
  Card,
  FormLayout,
  TextField,
  Button,
  Banner,
  Badge,
  Text,
  BlockStack,
  InlineStack,
  Checkbox,
  Link,
  Divider,
  Box,
} from "@shopify/polaris";
import { authenticate } from "../shopify.server";
import { getSettings, saveSettings } from "../lib/gembapay.server";

export const loader = async ({ request }) => {
  const { session } = await authenticate.admin(request);
  const settings = getSettings(session.shop);

  return json({
    shop: session.shop,
    settings: {
      ...settings,
      apiKey: settings.apiKey ? "••••••••" + settings.apiKey.slice(-8) : "",
    },
    hasApiKey: !!settings.apiKey,
    isTestMode: settings.apiKey?.startsWith("gembapay_test_") ?? false,
  });
};

export const action = async ({ request }) => {
  const { session } = await authenticate.admin(request);
  const formData = await request.formData();

  const apiKey = formData.get("apiKey");
  const webhookSecret = formData.get("webhookSecret");
  const enabled = formData.get("enabled") === "true";

  saveSettings(session.shop, { apiKey, webhookSecret, enabled });

  return json({ success: true });
};

export default function Settings() {
  const { settings, hasApiKey, isTestMode } = useLoaderData();
  const submit = useSubmit();
  const navigation = useNavigation();
  const saving = navigation.state === "submitting";

  const [apiKey, setApiKey] = useState("");
  const [webhookSecret, setWebhookSecret] = useState("");
  const [enabled, setEnabled] = useState(settings.enabled);

  const handleSave = useCallback(() => {
    const formData = new FormData();
    if (apiKey) formData.append("apiKey", apiKey);
    if (webhookSecret) formData.append("webhookSecret", webhookSecret);
    formData.append("enabled", enabled.toString());
    submit(formData, { method: "post" });
  }, [apiKey, webhookSecret, enabled, submit]);

  return (
    <Page title="GembaPay Settings">
      <BlockStack gap="500">
        {hasApiKey && (
          <Banner
            title={isTestMode ? "Test Mode Active" : "Live Mode Active"}
            tone={isTestMode ? "warning" : "success"}
          >
            <p>
              {isTestMode
                ? "Payments are processed on testnets and sandbox environments."
                : "Payments are being processed with real funds."}
            </p>
          </Banner>
        )}

        <Layout>
          <Layout.Section>
            <Card>
              <BlockStack gap="400">
                <Text variant="headingMd" as="h2">
                  API Configuration
                </Text>

                <FormLayout>
                  <TextField
                    label="API Key"
                    value={apiKey}
                    onChange={setApiKey}
                    placeholder={hasApiKey ? settings.apiKey : "gembapay_live_your_api_key"}
                    helpText={
                      <>
                        Get your API key from the{" "}
                        <Link url="https://merchant.gembapay.com" target="_blank">
                          Merchant Dashboard
                        </Link>
                        . Use gembapay_test_... for testing.
                      </>
                    }
                    type="password"
                    autoComplete="off"
                  />

                  <TextField
                    label="Webhook Secret"
                    value={webhookSecret}
                    onChange={setWebhookSecret}
                    placeholder="Your webhook signing secret"
                    helpText="Found in your Merchant Dashboard webhook settings."
                    type="password"
                    autoComplete="off"
                  />

                  <Checkbox
                    label="Enable GembaPay payments"
                    checked={enabled}
                    onChange={setEnabled}
                  />
                </FormLayout>

                <InlineStack align="end">
                  <Button variant="primary" onClick={handleSave} loading={saving}>
                    Save Settings
                  </Button>
                </InlineStack>
              </BlockStack>
            </Card>
          </Layout.Section>

          <Layout.Section variant="oneThird">
            <BlockStack gap="400">
              <Card>
                <BlockStack gap="300">
                  <Text variant="headingMd" as="h2">
                    Payment Methods
                  </Text>
                  <BlockStack gap="200">
                    <InlineStack gap="200" align="start">
                      <Badge tone="success">Crypto</Badge>
                      <Text variant="bodyMd" as="span">
                        ETH, BNB, POL, USDC, USDT
                      </Text>
                    </InlineStack>
                    <InlineStack gap="200" align="start">
                      <Badge tone="info">Stripe</Badge>
                      <Text variant="bodyMd" as="span">
                        Cards, Apple Pay, Google Pay
                      </Text>
                    </InlineStack>
                    <InlineStack gap="200" align="start">
                      <Badge>PayPal</Badge>
                      <Text variant="bodyMd" as="span">
                        Balance, Bank, Pay Later
                      </Text>
                    </InlineStack>
                  </BlockStack>
                </BlockStack>
              </Card>

              <Card>
                <BlockStack gap="300">
                  <Text variant="headingMd" as="h2">
                    Resources
                  </Text>
                  <BlockStack gap="100">
                    <Link url="https://docs.gembapay.com" target="_blank">
                      Documentation
                    </Link>
                    <Link url="https://docs.gembapay.com/integration" target="_blank">
                      Integration Guide
                    </Link>
                    <Link url="https://merchant.gembapay.com" target="_blank">
                      Merchant Dashboard
                    </Link>
                    <Link url="https://github.com/ivanovslavy/gembapay" target="_blank">
                      GitHub
                    </Link>
                  </BlockStack>
                </BlockStack>
              </Card>

              <Card>
                <BlockStack gap="200">
                  <Text variant="headingMd" as="h2">
                    Fees
                  </Text>
                  <Text variant="bodyMd" as="p">
                    Crypto: 1% (non-custodial)
                  </Text>
                  <Text variant="bodyMd" as="p">
                    Stripe: 1% + €0.20 + Stripe fees
                  </Text>
                  <Text variant="bodyMd" as="p">
                    PayPal: 1% + €0.20 + PayPal fees
                  </Text>
                </BlockStack>
              </Card>
            </BlockStack>
          </Layout.Section>
        </Layout>
      </BlockStack>
    </Page>
  );
}
