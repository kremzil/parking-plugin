<!doctype html>
<html lang="{{lang}}">
  <head>
    <meta charset="utf-8">
    <title>{{t_title}}</title>
  </head>
  <body style="margin:0;padding:0;background:#f4f6f8;font-family:-apple-system,system-ui,Segoe UI,Roboto,Arial,sans-serif;">
    

    <!-- Main container -->
    <div style="max-width:540px;margin:40px auto;background:#fff;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.08);overflow:hidden;">
      
      <!-- Banner -->
      <div style="background-image: url(https://www.pkosice.sk/wp-content/uploads/2025/08/banner-bg-01.jpg); text-align:center;padding:30px;background-size:cover;background-position:center;">
        <img src="https://www.pkosice.sk/wp-content/uploads/2025/08/pkosice-LOGO-03.png" alt="{{t_logo_alt}}" style="height:80px;">
        <h2 style="background-color: #364eff; border-radius: 8px; padding:1rem; margin:20px 0 0;font-size:22px;color:#fff;">{{t_banner_title}}</h2>
      </div>

      <!-- Content -->
      <div style="padding:30px;color:#333;font-size:15px;line-height:1.6;">
        <p>{{t_intro}}</p>
        <table style="width:100%;border-collapse:collapse;margin:20px 0;">
          <tr>
            <td style="padding:8px 0;color:#666;">{{t_label_spz}}</td>
            <td style="padding:8px 0;font-weight:bold;">{{spz}}</td>
          </tr>
          <tr style="background:#f9f9f9;">
            <td style="padding:8px 0;color:#666;">{{t_label_zone}}</td>
            <td style="padding:8px 0;font-weight:bold;">{{zone_id}}</td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#666;">{{t_label_duration}}</td>
            <td style="padding:8px 0;font-weight:bold;">{{minutes}} {{t_minutes_unit}}</td>
          </tr>
          <tr style="background:#f9f9f9;">
            <td style="padding:8px 0;color:#666;">{{t_label_amount}}</td>
            <td style="padding:8px 0;font-weight:bold;">{{amount_eur}} €</td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:#666;">{{t_label_paid_at}}</td>
            <td style="padding:8px 0;font-weight:bold;">{{paid_at}}</td>
          </tr>
        </table>

        <p style="margin-top:30px;font-size:13px;color:#888;text-align:center;">
          {{t_footer_note}}<br>
          {{t_thanks}}
        </p>
      </div>
    </div>

    <!-- Footer -->
    <div style="text-align:center;font-size:12px;color:#999;margin:30px 0;">
      {{t_copyright}}
    </div>

  </body>
</html>
