import React, { useState } from 'react';
import { useXpay } from '@xstak/xpay-element-stage';
import './index.css'

export const Payment = () => {
    const [loading, setLoading] = useState(false);
    const options = {
        override: true,
        fields: {
          creditCard: {
            placeholder: "1234 1234 1234 1234",
            label: "Enter your credit card",
          },
          exp: {
            placeholder: "Exp. Date",
          },
        },
        style: {
          ".input": {},
          ".invalid": {},
          ".label": {},
          ":focus": {},
          ":hover": {},
          "::placeholder": {},
          "::selection": {},
        },
      };

    const xpay = useXpay( "xpay_pk_test_a706b69e678f44a99ee9f2ee03963f158f665a1b7b8c60ac5ccf217d9b5e024a","c28721b349294cad","e18efac678237ee63ac2ccc44325eaae9e17ad77aeafb2e50166c4ad3e7f2c68");
    const app = xpay.element("#wc_xpay_widget", options);
}