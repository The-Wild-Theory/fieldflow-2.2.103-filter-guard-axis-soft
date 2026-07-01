<?php
namespace RoutesPro\Forms;
if (!defined('ABSPATH')) exit;
class FormRenderer {
    public static function enqueue_assets() {
        wp_register_style('routespro-form-renderer', false, [], ROUTESPRO_VERSION); wp_enqueue_style('routespro-form-renderer'); wp_add_inline_style('routespro-form-renderer', self::inline_css());
        wp_register_script('routespro-form-renderer', false, ['jquery'], ROUTESPRO_VERSION, true); wp_enqueue_script('routespro-form-renderer'); wp_add_inline_script('routespro-form-renderer', self::inline_js());
    }
    public static function theme_style_attr(array $theme): string { $parts=[]; if (!empty($theme['primary'])) $parts[]='--rp-form-primary:'.sanitize_hex_color($theme['primary']); if (!empty($theme['primary_hover'])) $parts[]='--rp-form-primary-hover:'.sanitize_hex_color($theme['primary_hover']); if (isset($theme['radius'])&&$theme['radius']!=='') $parts[]='--rp-form-radius:'.max(0,min(30,(int)$theme['radius'])).'px'; return implode(';', array_filter($parts)); }
    public static function render_questions(array $schema, array $prefill = [], array $context = []): string {
        $questions = isset($schema['questions']) && is_array($schema['questions']) ? $schema['questions'] : []; if (!$questions) return '<p>Formulário sem perguntas.</p>';
        $layout = isset($schema['layout']) && is_array($schema['layout']) ? $schema['layout'] : []; $mode = isset($layout['mode']) ? sanitize_key($layout['mode']) : 'single'; if (!in_array($mode,['single','steps'],true)) $mode='single'; $field_layout = isset($layout['field_layout']) && is_array($layout['field_layout']) ? $layout['field_layout'] : [];
        $title = sanitize_text_field($schema['meta']['title'] ?? ''); $subtitle = sanitize_text_field($schema['meta']['subtitle'] ?? ''); $index=[]; foreach ($questions as $q) { if (!is_array($q)) continue; $key = sanitize_key($q['key'] ?? ''); if ($key) $index[$key]=$q; }
        $out=''; if ($title || $subtitle) { $out.='<div class="routespro-form-section">'; if ($title) $out.='<h3 class="routespro-form-section-title">'.esc_html($title).'</h3>'; if ($subtitle) $out.='<div class="routespro-form-help">'.esc_html($subtitle).'</div>'; $out.='</div>'; }
        if ($mode === 'steps') {
            $steps = isset($layout['steps']) && is_array($layout['steps']) ? $layout['steps'] : []; if (!$steps) $steps=[['title'=>'','description'=>'','fields'=>array_keys($index)]]; $total=count($steps); $show_progress=!empty($layout['show_progress']) && $total>1; $out.='<div class="routespro-form-wizard" data-steps-total="'.esc_attr((string)$total).'">'; if($show_progress){$out.='<div class="routespro-form-progress"><div class="routespro-form-progress-bar"><span class="routespro-form-progress-fill" style="width:0%"></span></div><div class="routespro-form-progress-label" data-rp-progress-label>0%</div></div>';}
            foreach($steps as $i=>$st){$step_index=$i+1; $title_step=sanitize_text_field($st['title'] ?? ''); $desc_step=sanitize_text_field($st['description'] ?? ''); $fields=isset($st['fields'])&&is_array($st['fields'])?$st['fields']:[]; $out.='<div class="routespro-form-step" data-rp-step data-step-index="'.esc_attr((string)$step_index).'"'.($step_index===1?'':' hidden').'>'; if($title_step) $out.='<h3 class="routespro-form-step-title">'.esc_html($title_step).'</h3>'; if($desc_step) $out.='<div class="routespro-form-help">'.esc_html($desc_step).'</div>'; $out.='<div class="routespro-form-grid routespro-form-cols-2">'; foreach($fields as $key){$key=sanitize_key($key); if(empty($index[$key])) continue; $width=isset($field_layout[$key]['width'])?(int)$field_layout[$key]['width']:100; $out.=self::wrap_field_width(self::render_field($index[$key], $prefill[$key] ?? null, $context), $width, $index[$key]);} $out.='</div><div class="routespro-form-wizard-actions">'; if($step_index>1) $out.='<button type="button" class="routespro-form-btn secondary" data-rp-prev>Anterior</button>'; if($step_index<$total) $out.='<button type="button" class="routespro-form-btn" data-rp-next>Seguinte</button>'; else $out.='<button type="button" class="routespro-form-btn" data-rp-submit>Submeter</button>'; $out.='</div></div>';}
            return $out.'</div>';
        }
        $out.='<div class="routespro-form-section"><div class="routespro-form-grid routespro-form-cols-1">'; foreach($questions as $q){ if (!is_array($q)) continue; $k=sanitize_key($q['key'] ?? ''); $out.=self::wrap_field_width(self::render_field($q, $prefill[$k] ?? null, $context), 100, $q);} return $out.'</div></div>';
    }
    public static function schema_needs_multipart(array $schema): bool { foreach(($schema['questions'] ?? []) as $q){ if(!is_array($q)) continue; $type=sanitize_key($q['type'] ?? ''); if(in_array($type,['image_upload','file_upload'],true)) return true; } return false; }
    private static function wrap_field_width(string $html, int $width, array $q = []): string { $allowed=[25,33,50,66,75,100]; $width=in_array($width,$allowed,true)?$width:100; $key = sanitize_key($q['key'] ?? ''); $attrs = ' class="routespro-form-col routespro-form-w-'.esc_attr((string)$width).'"'; if ($key) { $attrs .= ' data-rp-question-wrap="'.esc_attr($key).'"'; } return '<div'.$attrs.'>'.$html.'</div>'; }
    private static function render_field(array $q, $prefill = null, array $context = []): string {
        $key = sanitize_key($q['key'] ?? ''); if(!$key) return '';
        $label = sanitize_text_field($q['label'] ?? $key);
        $type = sanitize_key($q['type'] ?? 'text');
        $required = !empty($q['required']);
        $help = sanitize_text_field($q['help_text'] ?? '');
        $min = $q['min'] ?? '';
        $max = $q['max'] ?? '';
        $unit = sanitize_text_field($q['unit'] ?? '');
        $options = is_array($q['options'] ?? null) ? $q['options'] : [];
        $req = $required ? ' required' : '';
        $value_attr = '';
        if (!is_array($prefill)) {
            $value_attr = is_scalar($prefill) ? (string) $prefill : '';
        }
        if ($type === 'product_matrix') {
            $rows = class_exists('\RoutesPro\Forms\ProductCardex') ? ProductCardex::question_rows($q, $context, $prefill) : self::product_rows_from_question($q, $prefill);
            $out = '<div class="routespro-form-field routespro-form-type-product_matrix" data-rp-question="'.esc_attr($key).'" data-rp-type="product_matrix">';
            $out .= '<div class="routespro-product-matrix-head"><label>'.esc_html($label).($required?' *':'').'</label><button type="button" class="routespro-form-btn secondary routespro-product-add" data-product-add="'.esc_attr($key).'">Adicionar produto</button></div>';
            $out .= '<div class="routespro-product-matrix" data-product-matrix="'.esc_attr($key).'">'.self::product_matrix_header($rows);
            if (!$rows) $rows = [['ref'=>'','name'=>'','qty'=>'']];
            foreach ($rows as $i => $row) {
                $out .= self::render_product_row($key, (int)$i, (array)$row, $required && $i === 0);
            }
            $out .= '</div>';
            if ($help) $out .= '<div class="routespro-form-help">'.esc_html($help).'</div>';
            $out .= '</div>';
            return $out;
        }
        $condition_attr = ''; if (!empty($q['conditions']) && is_array($q['conditions']) && !empty($q['conditions']['enabled']) && !empty($q['conditions']['go_to'])) { $condition_attr .= ' data-rp-condition-value="'.esc_attr((string) ($q['conditions']['value'] ?? '')).'"'; $condition_attr .= ' data-rp-condition-goto="'.esc_attr(sanitize_key((string) ($q['conditions']['go_to'] ?? ''))).'"'; } $out = '<div class="routespro-form-field routespro-form-type-'.esc_attr($type).'" data-rp-question="'.esc_attr($key).'" data-rp-type="'.esc_attr($type).'"'.$condition_attr.'>';
        if ($type === 'checkbox') {
            $checked = !empty($prefill) ? ' checked' : '';
            $out .= '<label class="routespro-form-check"><input type="checkbox" name="'.esc_attr($key).'" value="1"'.$checked.'> <span>'.esc_html($label).($required?' *':'').'</span></label>';
        } elseif ($type === 'radio') {
            $out .= '<fieldset class="routespro-form-fieldset"><legend>'.esc_html($label).($required?' *':'').'</legend>';
            foreach ($options as $opt) {
                $opt = sanitize_text_field($opt);
                $checked = ((string)$value_attr === (string)$opt) ? ' checked' : '';
                $out .= '<label class="routespro-form-check"><input type="radio" name="'.esc_attr($key).'" value="'.esc_attr($opt).'"'.$req.$checked.'> <span>'.esc_html($opt).'</span></label>';
            }
            $out .= '</fieldset>';
        } else {
            $out .= '<label for="'.esc_attr($key).'">'.esc_html($label).($required?' *':'').'</label>';
            switch ($type) {
                case 'textarea':
                    $out .= '<textarea id="'.esc_attr($key).'" name="'.esc_attr($key).'" rows="4"'.$req.'>'.esc_textarea($value_attr).'</textarea>';
                    break;
                case 'number': case 'currency': case 'percent':
                    $out .= '<div class="routespro-form-input-group">';
                    if ($type === 'currency') $out .= '<span class="routespro-form-addon">€</span>';
                    $out .= '<input type="number" step="0.01" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value_attr).'"'.($min!==''?' min="'.esc_attr((string)$min).'"':'').($max!==''?' max="'.esc_attr((string)$max).'"':'').$req.'>';
                    if ($type === 'percent') $out .= '<span class="routespro-form-addon">%</span>';
                    elseif ($unit) $out .= '<span class="routespro-form-addon">'.esc_html($unit).'</span>';
                    $out .= '</div>';
                    break;
                case 'date':
                    $out .= '<input type="date" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value_attr).'"'.$req.'>';
                    break;
                case 'time':
                    $out .= '<input type="time" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value_attr).'"'.$req.'>';
                    break;
                case 'select':
                    $out .= '<select id="'.esc_attr($key).'" name="'.esc_attr($key).'"'.$req.'><option value="">Selecionar</option>';
                    foreach ($options as $opt) {
                        $opt = sanitize_text_field($opt);
                        $selected = selected((string)$value_attr, (string)$opt, false);
                        $out .= '<option value="'.esc_attr($opt).'"'.$selected.'>'.esc_html($opt).'</option>';
                    }
                    $out .= '</select>';
                    break;
                case 'image_upload':
                    $multiple = !empty($q['multiple']) ? ' multiple' : '';
                    $field_name = !empty($q['multiple']) ? $key.'[]' : $key;
                    $out .= '<input type="file" accept="image/*" id="'.esc_attr($key).'" name="'.esc_attr($field_name).'"'.$multiple.$req.'>';
                    if (!empty($prefill)) {
                        $current = is_array($prefill) ? $prefill : [$prefill];
                        $links = [];
                        foreach ($current as $url) { if (!$url) continue; $links[] = '<a href="'.esc_url((string)$url).'" target="_blank" rel="noopener">abrir</a>'; }
                        if ($links) $out .= '<div class="routespro-form-help">Imagens atuais: '.implode(', ', $links).'</div>';
                    }
                    break;
                case 'file_upload':
                    $multiple = !empty($q['multiple']) ? ' multiple' : '';
                    $field_name = !empty($q['multiple']) ? $key.'[]' : $key;
                    $out .= '<input type="file" id="'.esc_attr($key).'" name="'.esc_attr($field_name).'"'.$multiple.$req.'>';
                    if (!empty($prefill)) {
                        $current = is_array($prefill) ? $prefill : [$prefill];
                        $links = [];
                        foreach ($current as $url) { if (!$url) continue; $links[] = '<a href="'.esc_url((string)$url).'" target="_blank" rel="noopener">abrir</a>'; }
                        if ($links) $out .= '<div class="routespro-form-help">Ficheiros atuais: '.implode(', ', $links).'</div>';
                    }
                    break;
                default:
                    $out .= '<input type="text" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value_attr).'"'.$req.'>';
                    break;
            }
        }
        if ($help) $out .= '<div class="routespro-form-help">'.esc_html($help).'</div>';
        return $out.'</div>';
    }
    private static function product_rows_from_question(array $q, $prefill = null): array {
        $base = [];
        if (isset($q['product_rows']) && is_array($q['product_rows'])) $base = $q['product_rows'];
        elseif (isset($q['products']) && is_array($q['products'])) $base = $q['products'];
        $out = [];
        foreach ($base as $row) {
            if (!is_array($row)) continue;
            $out[] = [
                'ref' => sanitize_text_field((string)($row['ref'] ?? $row['reference'] ?? '')),
                'name' => sanitize_text_field((string)($row['name'] ?? $row['product'] ?? '')),
                'qty' => '', 'before' => '', 'after' => '', 'has_history' => 0,
            ];
        }
        if (is_array($prefill)) {
            $history = [];
            foreach ($prefill as $row) {
                if (!is_array($row)) continue;
                $ref = sanitize_text_field((string)($row['ref'] ?? $row['reference'] ?? ''));
                $name = sanitize_text_field((string)($row['name'] ?? $row['product'] ?? ''));
                $qty = isset($row['qty']) ? (string)$row['qty'] : (isset($row['after']) ? (string)$row['after'] : (isset($row['quantity']) ? (string)$row['quantity'] : ''));
                $before = isset($row['before']) ? (string)$row['before'] : $qty;
                $after = isset($row['after']) ? (string)$row['after'] : $qty;
                $history[] = ['ref'=>$ref,'name'=>$name,'qty'=>$qty,'before'=>$before,'after'=>$after,'has_history'=>1];
            }
            if ($history) $out = $history;
        }
        return $out;
    }
    private static function product_matrix_has_history(array $rows): bool { foreach ($rows as $row) { if (is_array($row) && !empty($row['has_history'])) return true; } return false; }
    private static function product_matrix_header(array $rows): string {
        if (self::product_matrix_has_history($rows)) {
            return '<div class="routespro-product-row routespro-product-row-head routespro-product-row-history"><span>Referência</span><span>Produto</span><span>Frentes antes</span><span>Frentes depois</span><span></span></div>';
        }
        return '<div class="routespro-product-row routespro-product-row-head"><span>Referência</span><span>Produto</span><span>Número de frentes</span><span></span></div>';
    }
    private static function render_product_row(string $key, int $i, array $row, bool $required = false): string {
        $ref = sanitize_text_field((string)($row['ref'] ?? ''));
        $name = sanitize_text_field((string)($row['name'] ?? ''));
        $qty = (string)($row['qty'] ?? '');
        $before = (string)($row['before'] ?? $qty);
        $after = (string)($row['after'] ?? $qty);
        $has_history = !empty($row['has_history']);
        $req = $required ? ' required' : '';
        $html = '<div class="routespro-product-row'.($has_history?' routespro-product-row-history':'').'" data-product-row>'
            . '<input type="text" name="'.esc_attr($key).'['.esc_attr((string)$i).'][ref]" value="'.esc_attr($ref).'" placeholder="Opcional">'
            . '<input type="text" name="'.esc_attr($key).'['.esc_attr((string)$i).'][name]" value="'.esc_attr($name).'" placeholder="Nome do produto"'.$req.'>';
        if ($has_history) {
            $html .= '<input type="number" step="0.01" min="0" name="'.esc_attr($key).'['.esc_attr((string)$i).'][before]" value="'.esc_attr($before).'" placeholder="0" readonly class="routespro-product-before">'
                . '<input type="number" step="0.01" min="0" name="'.esc_attr($key).'['.esc_attr((string)$i).'][after]" value="'.esc_attr($after).'" placeholder="0"'.$req.'>';
        } else {
            $html .= '<input type="number" step="0.01" min="0" name="'.esc_attr($key).'['.esc_attr((string)$i).'][qty]" value="'.esc_attr($qty).'" placeholder="0"'.$req.'>';
        }
        return $html . '<button type="button" class="routespro-product-remove" aria-label="Remover linha">×</button></div>';
    }
    private static function inline_css(): string { return '.rp-form-ui{--rp-form-primary:#2271b1;--rp-form-primary-hover:#135e96;--rp-form-radius:14px;color:#111827;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}.routespro-form-section{background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:var(--rp-form-radius);padding:16px;margin:0 0 14px}.routespro-form-section-title,.routespro-form-step-title{font-size:18px;margin:0 0 8px}.routespro-form-help{font-size:13px;opacity:.78;margin-top:6px;line-height:1.35}.routespro-form-grid{display:grid;gap:12px}.routespro-form-cols-1{grid-template-columns:1fr}.routespro-form-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.routespro-form-col{min-width:0}.routespro-form-w-25,.routespro-form-w-33,.routespro-form-w-50{grid-column:span 1}.routespro-form-w-66,.routespro-form-w-75,.routespro-form-w-100{grid-column:span 2}.routespro-form-field label,.routespro-form-field legend{display:block;margin:0 0 6px;font-size:13px}.routespro-form-field input[type=text],.routespro-form-field input[type=number],.routespro-form-field input[type=date],.routespro-form-field input[type=time],.routespro-form-field input[type=file],.routespro-form-field select,.routespro-form-field textarea{width:100%;box-sizing:border-box;border:1px solid rgba(0,0,0,.18);border-radius:calc(var(--rp-form-radius) - 2px);padding:10px 12px;background:#fff}.routespro-form-fieldset{border:0;padding:0;margin:0;min-width:0}.routespro-form-check{display:flex;gap:10px;align-items:flex-start;margin:8px 0}.routespro-form-input-group{display:flex;align-items:center;border:1px solid rgba(0,0,0,.18);border-radius:calc(var(--rp-form-radius) - 2px);overflow:hidden;background:#fff}.routespro-form-input-group input{border:0!important;border-radius:0!important}.routespro-form-addon{padding:0 10px;opacity:.75;white-space:nowrap}.routespro-form-btn{appearance:none;border:0;border-radius:999px;padding:11px 16px;background:var(--rp-form-primary);color:#fff;font-weight:600;cursor:pointer}.routespro-form-btn:hover{background:var(--rp-form-primary-hover)}.routespro-form-btn.secondary{background:#f3f4f6;color:#111827}.routespro-form-actions,.routespro-form-wizard-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.routespro-form-progress{margin:0 0 14px}.routespro-form-progress-bar{height:10px;background:#eef2f7;border-radius:999px;overflow:hidden}.routespro-form-progress-fill{display:block;height:100%;background:var(--rp-form-primary)}.routespro-form-progress-label{font-size:12px;opacity:.75;margin-top:6px}.routespro-form-notice{padding:12px 14px;border-radius:12px;margin:0 0 12px}.routespro-form-notice.success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.routespro-form-notice.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.routespro-form-notice.info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.routespro-product-matrix-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.routespro-product-matrix-head label{margin:0!important;font-weight:700}.routespro-product-matrix{display:grid;gap:8px}.routespro-product-row{display:grid;grid-template-columns:1fr 2fr 130px 38px;gap:8px;align-items:center}.routespro-product-row-history{grid-template-columns:1fr 2fr 130px 130px 38px}.routespro-product-row-head{font-size:12px;font-weight:700;color:#64748b;padding:0 38px 0 0}.routespro-product-remove{height:38px;border:1px solid rgba(0,0,0,.16);background:#fff;border-radius:12px;cursor:pointer;font-size:18px;line-height:1}.routespro-product-remove:hover{background:#fee2e2;border-color:#fecaca}.routespro-product-add{padding:8px 12px!important}.routespro-product-row input{width:100%;box-sizing:border-box;border:1px solid rgba(0,0,0,.18);border-radius:calc(var(--rp-form-radius) - 2px);padding:10px 12px;background:#fff}.routespro-product-before{background:#f8fafc!important;color:#64748b}@media(max-width:720px){.routespro-form-cols-2{grid-template-columns:1fr}.routespro-form-w-66,.routespro-form-w-75,.routespro-form-w-100{grid-column:span 1}}'; }
    private static function inline_js(): string {
        return <<<'JS'
jQuery(function($){
  function rpInitDynamicForm(form){
    var $form=$(form||null);
    if(!$form.length || !$form.hasClass('routespro-dyn-form') || $form.data('rpWizardInit')) return;
    $form.data('rpWizardInit',1);
    var $wizard=$form.find('.routespro-form-wizard').first();
    function fieldValue($q){
      var type=String($q.attr('data-rp-type')||'');
      if(type==='checkbox') return $q.find('input[type=checkbox]').is(':checked') ? '1' : '0';
      if(type==='radio') return $q.find('input[type=radio]:checked').val() || '';
      var $input=$q.find('select, textarea, input').not('[type=hidden],[type=button],[type=submit]').first();
      return $input.val() || '';
    }
    function normalise(v){ return String(v===undefined||v===null?'':v).toLowerCase().trim(); }
    function visibleQuestions($scope){
      return $scope.find('[data-rp-question]').filter(function(){
        return !$(this).closest('[data-rp-question-wrap]').prop('hidden');
      });
    }
    function applyConditions(){
      $form.find('[data-rp-question-wrap]').each(function(){
        $(this).removeAttr('data-rp-force-show');
      });
      $form.find('[data-rp-question]').each(function(){
        var $q=$(this),goTo=$q.attr('data-rp-condition-goto')||'',expect=$q.attr('data-rp-condition-value');
        if(!goTo) return;
        var match = normalise(fieldValue($q)) === normalise(expect);
        var $targetWrap=$form.find('[data-rp-question-wrap="'+goTo+'"]');
        if($targetWrap.length) $targetWrap.attr('data-rp-force-show', match ? '1' : '0');
      });
      $form.find('[data-rp-question-wrap]').each(function(){
        var $wrap=$(this);
        if($wrap.attr('data-rp-force-show')==='1') $wrap.prop('hidden', false);
      });
    }
    $form.on('change input','input, select, textarea',applyConditions);
    applyConditions();
    if(!$wizard.length) return;
    var $steps=$wizard.find('[data-rp-step]');
    if(!$steps.length) return;
    var total=parseInt($wizard.attr('data-steps-total')||$steps.length,10)||$steps.length;
    var index=1;
    function stepByQuestionKey(key){
      var found=null;
      $steps.each(function(){
        var $s=$(this);
        if($s.find('[data-rp-question-wrap="'+key+'"]').length){ found=parseInt($s.attr('data-step-index')||'0',10)||null; return false; }
      });
      return found;
    }
    function showStep(n){
      index=Math.max(1,Math.min(total,n));
      $steps.each(function(){
        var $s=$(this),i=parseInt($s.attr('data-step-index')||'0',10);
        $s.prop('hidden',i!==index);
      });
      var pct=total<=1?100:Math.round(((index-1)/(total-1))*100);
      $wizard.find('.routespro-form-progress-fill').css('width',pct+'%');
      $wizard.find('[data-rp-progress-label]').text(pct+'%');
    }
    function validateScope($scope){
      var ok=true;
      visibleQuestions($scope).find('[required]').each(function(){
        if(this.type==='radio'){
          var name=$(this).attr('name');
          if(!$scope.find('input[type=radio][name="'+name+'"]:checked').length){ ok=false; return false; }
        } else if(this.type==='checkbox') {
          if(!$(this).is(':checked')) { ok=false; return false; }
        } else if(!$(this).val()) { ok=false; return false; }
      });
      return ok;
    }
    function nextStepIndex(){
      var $current=$wizard.find('[data-rp-step][data-step-index="'+index+'"]');
      var target=null;
      $current.find('[data-rp-question]').each(function(){
        var $q=$(this),goTo=$q.attr('data-rp-condition-goto')||'',expect=$q.attr('data-rp-condition-value');
        if(!goTo) return;
        if(normalise(fieldValue($q))===normalise(expect)){
          var stepIdx=stepByQuestionKey(goTo);
          if(stepIdx && stepIdx>index){ target=stepIdx; return false; }
        }
      });
      return target || (index+1);
    }
    $wizard.on('click','[data-rp-next]',function(e){
      e.preventDefault();
      var $current=$wizard.find('[data-rp-step][data-step-index="'+index+'"]');
      applyConditions();
      if(!validateScope($current)){ alert('Há campos obrigatórios por preencher.'); return; }
      showStep(nextStepIndex());
    });
    $wizard.on('click','[data-rp-prev]',function(e){ e.preventDefault(); showStep(index-1); });
    $wizard.on('click','[data-rp-submit]',function(e){
      e.preventDefault();
      applyConditions();
      if(!validateScope($form)){ alert('Há campos obrigatórios por preencher.'); return; }
      if(form.requestSubmit){ form.requestSubmit(); }
      else { form.submit(); }
    });
    $form.find('.routespro-form-actions').prop('hidden',true);
    showStep(1);
  }


  $(document).on('click','.routespro-product-add',function(e){
    e.preventDefault();
    var key=$(this).attr('data-product-add')||'';
    var $box=$('[data-product-matrix="'+key.replace(/"/g,'\\"')+'"]').first();
    if(!$box.length) return;
    var i=$box.find('[data-product-row]').length;
    var hasHistory=$box.find('.routespro-product-row-head').hasClass('routespro-product-row-history');
    var row='<div class="routespro-product-row'+(hasHistory?' routespro-product-row-history':'')+'" data-product-row>'+
      '<input type="text" name="'+key+'['+i+'][ref]" value="" placeholder="Opcional">'+
      '<input type="text" name="'+key+'['+i+'][name]" value="" placeholder="Nome do produto">'+
      (hasHistory ? '<input type="number" step="0.01" min="0" name="'+key+'['+i+'][before]" value="" placeholder="0" readonly class="routespro-product-before"><input type="number" step="0.01" min="0" name="'+key+'['+i+'][after]" value="" placeholder="0">' : '<input type="number" step="0.01" min="0" name="'+key+'['+i+'][qty]" value="" placeholder="0">')+
      '<button type="button" class="routespro-product-remove" aria-label="Remover linha">×</button>'+
    '</div>';
    $box.append(row);
  });
  $(document).on('click','.routespro-product-remove',function(e){
    e.preventDefault();
    var $row=$(this).closest('[data-product-row]');
    var $box=$row.closest('[data-product-matrix]');
    if($box.find('[data-product-row]').length<=1){ $row.find('input').val(''); return; }
    $row.remove();
  });
  window.routesproInitDynamicForm = rpInitDynamicForm;
  $('.routespro-dyn-form').each(function(){ rpInitDynamicForm(this); });
  $(document).on('routespro:form-rendered', function(_e, form){ if(form) rpInitDynamicForm(form); });
});
JS;
    }
}


