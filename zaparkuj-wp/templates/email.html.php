<!doctype html>
<html lang="{{lang}}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{t_title}}</title>
    <style>
      body {
        margin: 0;
        padding: 0;
        background-color: #f3f4f6;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
        line-height: 1.5;
      }
      .container {
        max-width: 600px;
        margin: 0 auto;
        background-color: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
      }
      .outer {
        padding: 40px 20px;
      }
      .header {
        background: linear-gradient(135deg, #030213 0%, #1f2937 100%);
        padding: 30px 20px;
        text-align: center;
      }
      .logo-row{
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
      }
      .logo-mark{
        position: relative;
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
      }
      .logo-mark-bg{
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #2563eb 0%, #4338ca 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 18px rgba(0,0,0,0.25);
      }
      .logo-circle{
        width: 26px;
        height: 26px;
        border-radius: 999px;
        border: 2px solid rgba(255,255,255,0.85);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        font-weight: 900;
        font-size: 16px;
        line-height: 1;
      }
      .logo-dot{
        position: absolute;
        top: -2px;
        right: -2px;
        width: 12px;
        height: 12px;
        border-radius: 999px;
        background: #22c55e;
        border: 2px solid #ffffff;
      }
      .logo-text{
        display: inline-block;
        text-align: left;
        line-height: 1.05;
      }
      .logo {
        font-size: 28px;
        font-weight: 800;
        color: #ffffff;
        margin: 0;
        letter-spacing: -0.5px;
      }
      .logo-accent{ color: #60a5fa; }
      .logo-subtitle {
        font-size: 14px;
        color: rgba(255, 255, 255, 0.8);
        margin-top: 4px;
      }
      .success-icon {
        text-align: center;
        padding: 30px 0 10px;
        background-color: #ffffff;
      }
      .checkmark {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background-color: #10b981;
        margin: 0 auto;
        position: relative;
        box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
      }
      .checkmark:after {
        content: '';
        position: absolute;
        left: 28px;
        top: 40px;
        width: 16px;
        height: 32px;
        border: solid white;
        border-width: 0 6px 6px 0;
        transform: rotate(45deg);
      }
      .content {
        padding: 0 30px 30px;
      }
      .greeting {
        font-size: 18px;
        font-weight: 700;
        color: #111827;
        margin: 0 0 8px;
        text-align: center;
      }
      .thank-you {
        font-size: 22px;
        font-weight: 800;
        color: #111827;
        margin: 0 0 12px;
        text-align: center;
      }
      .confirmation-text {
        font-size: 14px;
        color: #6b7280;
        margin: 0 0 20px;
        text-align: center;
      }
      .qr-section {
        background-color: #f9fafb;
        border-radius: 12px;
        padding: 20px;
        margin: 24px 0;
        text-align: center;
        border: 1px solid #e5e7eb;
      }
      .qr-code {
        width: 180px;
        height: 180px;
        background-color: #ffffff;
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6b7280;
        font-size: 12px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
        padding: 10px;
        box-sizing: border-box;
        word-break: break-all;
      }
      .section-title {
        font-size: 16px;
        font-weight: 800;
        color: #111827;
        margin: 28px 0 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e5e7eb;
      }
      .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #f3f4f6;
      }
      .detail-label {
        font-size: 14px;
        color: #6b7280;
        flex: 1;
      }
      .detail-value {
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        text-align: right;
        flex: 1;
      }
      .transaction-id {
        background-color: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 12px;
        padding: 14px;
        margin: 16px 0;
        text-align: center;
      }
      .transaction-id-label {
        font-size: 12px;
        color: #2563eb;
        font-weight: 800;
        margin-bottom: 6px;
      }
      .transaction-id-value {
        font-size: 12px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
        color: #1e3a8a;
        word-break: break-all;
      }
      .total-section {
        background: linear-gradient(135deg, #eff6ff 0%, #eef2ff 100%);
        border: 1px solid #bfdbfe;
        border-radius: 12px;
        padding: 16px;
        margin: 20px 0;
      }
      .total-label {
        font-size: 14px;
        color: #374151;
        font-weight: 700;
      }
      .total-value {
        font-size: 22px;
        font-weight: 900;
        color: #1d4ed8;
        float: right;
      }
      .info-box {
        background-color: #fef3c7;
        border-left: 4px solid #f59e0b;
        padding: 16px;
        margin: 24px 0;
        border-radius: 8px;
      }
      .info-title {
        font-size: 14px;
        font-weight: 800;
        color: #92400e;
        margin-bottom: 10px;
      }
      .info-item {
        font-size: 13px;
        color: #78350f;
        margin: 8px 0;
        padding-left: 16px;
        position: relative;
      }
      .info-item:before {
        content: '•';
        position: absolute;
        left: 0;
        font-weight: 800;
      }
      .help-section {
        background-color: #f9fafb;
        padding: 18px;
        text-align: center;
        border-radius: 12px;
        margin: 24px 0 0;
        border: 1px solid #e5e7eb;
      }
      .help-title {
        font-size: 14px;
        font-weight: 800;
        color: #111827;
        margin-bottom: 6px;
      }
      .help-text {
        font-size: 13px;
        color: #6b7280;
        margin-bottom: 10px;
      }
      .help-email {
        color: #2563eb;
        text-decoration: none;
        font-weight: 800;
      }
      .footer {
        background-color: #111827;
        color: #9ca3af;
        text-align: center;
        padding: 22px 20px;
        font-size: 12px;
      }
      .footer-text {
        margin: 6px 0;
      }
    </style>
  </head>
  <body>
    <div class="outer">
      <div class="container">
        <div class="header">
          <div class="logo-row">
            <div class="logo-mark" aria-hidden="true">
              <div class="logo-mark-bg">
                <div class="logo-circle">P</div>
              </div>
              <div class="logo-dot"></div>
            </div>
            <div class="logo-text">
              <div class="logo">parkovne<span class="logo-accent">.sk</span></div>
              <div class="logo-subtitle">inteligentné parkovanie</div>
            </div>
          </div>
        </div>

        <div class="success-icon">
          <div class="checkmark" aria-hidden="true"></div>
        </div>

        <div class="content">
          <div class="greeting">{{t_greeting}}!</div>
          <div class="thank-you">{{t_thank_you}}</div>
          <div class="confirmation-text">{{t_confirmation_text}}</div>

          <div class="qr-section">
            <div class="qr-code">
              {{transaction_id}}
            </div>
            <div style="margin-top:10px;font-size:12px;color:#6b7280;">{{t_qr_label}}</div>
          </div>

          <div class="transaction-id">
            <div class="transaction-id-label">{{t_transaction_id}}</div>
            <div class="transaction-id-value">{{transaction_id}}</div>
          </div>

          <div class="section-title">{{t_parking_details}}</div>

          <div class="detail-row">
            <div class="detail-label">{{t_label_spz}}</div>
            <div class="detail-value">{{spz}}</div>
          </div>
          <div class="detail-row">
            <div class="detail-label">{{t_label_zone}}</div>
            <div class="detail-value">{{zone_label}}</div>
          </div>
          <div class="detail-row">
            <div class="detail-label">{{t_zone_number}}</div>
            <div class="detail-value">{{zone_id}}</div>
          </div>

          <div class="section-title">{{t_parking_period}}</div>
          <div class="detail-row">
            <div class="detail-label">{{t_from}}</div>
            <div class="detail-value">{{start_date}}, {{start_time}}</div>
          </div>
          <div class="detail-row">
            <div class="detail-label">{{t_to}}</div>
            <div class="detail-value">{{end_date}}, {{end_time}}</div>
          </div>
          <div class="detail-row">
            <div class="detail-label">{{t_label_duration}}</div>
            <div class="detail-value">{{duration_text}}</div>
          </div>

          <div class="section-title">{{t_payment_details}}</div>
          <div class="detail-row">
            <div class="detail-label">{{t_price_per_hour}}</div>
            <div class="detail-value">{{price_per_hour}}</div>
          </div>

          <div class="total-section">
            <span class="total-label">{{t_total_paid}}</span>
            <span class="total-value">{{amount_eur}} €</span>
            <div style="clear: both;"></div>
          </div>

          <div class="info-box">
            <div class="info-title">{{t_important_info}}</div>
            <div class="info-item">{{t_info1}}</div>
            <div class="info-item">{{t_info2}}</div>
            <div class="info-item">{{t_info3}}</div>
            <div class="info-item">{{t_info4}}</div>
          </div>

          <div class="help-section">
            <div class="help-title">{{t_need_help}}</div>
            <div class="help-text">
              {{t_contact_us}} <a href="mailto:{{support_email}}" class="help-email">{{support_email}}</a>
            </div>
          </div>

          <div style="margin-top:18px;font-size:12px;color:#9ca3af;text-align:center;">
            {{t_footer_note}}<br>
            {{t_thanks}}
          </div>
        </div>

        <div class="footer">
          <div class="footer-text">{{t_copyright}}</div>
          <div class="footer-text">{{support_email}}</div>
        </div>
      </div>
    </div>
  </body>
</html>
