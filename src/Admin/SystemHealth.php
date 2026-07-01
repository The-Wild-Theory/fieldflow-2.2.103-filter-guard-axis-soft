<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\AdminPage;
use RoutesPro\Support\SystemHealth as Health;

if (!defined('ABSPATH')) exit;

class SystemHealth {
    public static function render() {
        if (!current_user_can('routespro_manage')) return;
        $report = Health::status();
        AdminPage::open('Saúde do sistema', 'Diagnóstico técnico do produto, pronto para operação e distribuição.');
        ?>
        <style>
          .ff-health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:18px 0}
          .ff-health-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.05)}
          .ff-health-chip{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}
          .ff-health-chip.ok{background:#dcfce7;color:#166534}.ff-health-chip.warning{background:#fef3c7;color:#92400e}.ff-health-chip.critical{background:#fee2e2;color:#991b1b}
          .ff-health-columns{display:grid;grid-template-columns:2fr 1fr;gap:18px;align-items:start}
          @media (max-width: 1080px){.ff-health-columns{grid-template-columns:1fr}}
        </style>
        <div class="ff-health-grid">
          <?php foreach ($report['counts'] as $label => $count): ?>
            <div class="ff-health-card"><strong><?php echo esc_html(ucfirst($label)); ?></strong><div style="font-size:30px;font-weight:800;margin-top:8px"><?php echo esc_html(number_format_i18n((int)$count)); ?></div></div>
          <?php endforeach; ?>
        </div>
        <div class="ff-health-columns">
          <div class="ff-health-card">
            <h2 style="margin-top:0">Checks críticos</h2>
            <table class="widefat striped"><thead><tr><th>Área</th><th>Estado</th><th>Detalhe</th></tr></thead><tbody>
              <?php foreach ($report['checks'] as $check): ?>
                <tr>
                  <td><strong><?php echo esc_html($check['label']); ?></strong></td>
                  <td><span class="ff-health-chip <?php echo esc_attr($check['status']); ?>"><?php echo esc_html(strtoupper($check['status'])); ?></span></td>
                  <td><?php echo esc_html($check['detail']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table>
          </div>
          <div class="ff-health-card">
            <h2 style="margin-top:0">Licença e credenciais</h2>
            <p><strong>Licença:</strong> <?php echo esc_html(\RoutesPro\Support\LicenseManager::statusLabel()); ?></p>
            <p style="color:#475569">Para produto premium, o ideal é segredos sensíveis estarem no <code>wp-config.php</code>, não só na base de dados.</p>
            <table class="widefat striped"><thead><tr><th>Chave</th><th>Origem</th></tr></thead><tbody>
            <?php foreach ($report['constants'] as $key => $origin): ?>
              <tr><td><code><?php echo esc_html($key); ?></code></td><td><?php echo esc_html($origin); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          </div>
        </div>
        <?php
        AdminPage::close();
    }
}
