<?php

namespace GlpiPlugin\Termodocs;

/**
 * Starting values (not code) for a new document template's header, footer
 * and CSS - prefilled in the "Add" form so admins customize a working
 * design instead of starting from a blank page. Purely data: nothing
 * here is applied automatically or shared between templates once saved.
 */
class DocumentTemplateDefaults
{
    public static function getCss(): string
    {
        return <<<CSS
            * { box-sizing: border-box; margin: 0; padding: 0; }
            .td-page {
                font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                color: #1f2937;
                font-size: 11pt;
                line-height: 1.5;
                max-width: 800px;
                margin: 0 auto;
            }

            .td-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 2px solid #111827;
                padding-bottom: 14px;
                margin-bottom: 22px;
            }
            .td-header .logo { font-size: 18pt; font-weight: 800; color: #111827; letter-spacing: -0.5px; }
            .td-header .logo small {
                display: block; font-size: 8pt; font-weight: 400; color: #6b7280;
                letter-spacing: 2px; text-transform: uppercase; margin-top: 2px;
            }
            .td-header .doc-title { text-align: right; }
            .td-header .doc-title h2 {
                font-size: 14pt; font-weight: 700; color: #111827;
                text-transform: uppercase; letter-spacing: 1px;
            }
            .td-header .doc-title p { font-size: 9pt; color: #6b7280; margin-top: 4px; }

            .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; margin-bottom: 22px; }
            .info-item { border-left: 3px solid #2563eb; padding: 6px 0 6px 12px; }
            .info-item label {
                display: block; font-size: 8pt; font-weight: 600; color: #6b7280;
                text-transform: uppercase; letter-spacing: .8px; margin-bottom: 3px;
            }
            .info-item .value { font-size: 11pt; color: #111827; font-weight: 500; min-height: 18px; }

            .intro {
                background: #f9fafb; border-radius: 6px; padding: 14px 16px;
                margin-bottom: 22px; font-size: 10.5pt; color: #374151; text-align: justify;
            }
            .intro strong { color: #111827; }

            table.equipment { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 10pt; }
            table.equipment thead th {
                background: #111827; color: #fff; text-align: left; padding: 9px 10px;
                font-size: 9pt; font-weight: 600; text-transform: uppercase; letter-spacing: .6px;
            }
            table.equipment tbody td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
            table.equipment tbody tr:nth-child(even) td { background: #f9fafb; }

            .terms { margin-bottom: 22px; }
            .terms h3 {
                font-size: 11pt; color: #111827; text-transform: uppercase; letter-spacing: .8px;
                margin-bottom: 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 5px;
            }
            .terms .terms-body { font-size: 10pt; color: #374151; text-align: justify; }
            .terms .terms-body ol { padding-left: 20px; }
            .terms .terms-body li { margin-bottom: 6px; }

            .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; margin-top: 48px; }
            .signature-block { text-align: center; }
            .td-signature-name {
                font-family: 'Brush Script MT', 'Segoe Script', 'Lucida Handwriting', cursive;
                font-size: 20pt; color: #1d4ed8; min-height: 30px; line-height: 32px; margin-bottom: -6px;
            }
            .signature-block .line { border-top: 1px solid #111827; margin-bottom: 6px; height: 6px; }
            .signature-block .name { font-size: 10pt; font-weight: 600; color: #111827; }
            .td-signature-date { font-size: 7.5pt; color: #6b7280; margin-top: 2px; }
            .signature-block .role {
                font-size: 8pt; color: #6b7280; text-transform: uppercase; letter-spacing: .6px;
            }

            .td-footer {
                margin-top: 28px; padding-top: 10px; border-top: 1px solid #e5e7eb;
                font-size: 8pt; color: #9ca3af; text-align: center;
            }
            CSS;
    }

    public static function getHeaderHtml(): string
    {
        return <<<HTML
            <div class="logo">
                {{ entity.name }}
                <small>Termo de responsabilidade</small>
            </div>
            <div class="doc-title">
                <h2>Termo de Entrega</h2>
                <p>Data: {{ document.date }}</p>
            </div>
            HTML;
    }

    public static function getFooterHtml(): string
    {
        return 'Documento gerado eletronicamente &middot; {{ entity.name }} &middot; Página {PAGENO} de {nb}';
    }

    public static function getContentHtml(): string
    {
        return <<<HTML
            <div class="info-grid">
              <div class="info-item">
                <label>Colaborador (Recebedor)</label>
                <div class="value">{{ user.firstname }} {{ user.realname }}</div>
              </div>
              <div class="info-item">
                <label>Login / Matrícula</label>
                <div class="value">{{ user.name }}</div>
              </div>
              <div class="info-item">
                <label>E-mail corporativo</label>
                <div class="value">{{ user.email }}</div>
              </div>
              <div class="info-item">
                <label>Responsável pela entrega</label>
                <div class="value">{{ requester.firstname }} {{ requester.realname }}</div>
              </div>
              <div class="info-item">
                <label>Entidade</label>
                <div class="value">{{ entity.name }}</div>
              </div>
            </div>

            <div class="intro">
              Pelo presente instrumento, a <strong>{{ entity.name }}</strong> entrega ao(à)
              colaborador(a) acima identificado(a) os equipamentos descritos na tabela abaixo,
              para uso exclusivamente profissional, ficando o(a) mesmo(a) responsável pela guarda,
              conservação e devolução nas condições em que recebeu.
            </div>

            <table class="equipment">
              <thead>
                <tr>
                  <th style="width:6%">#</th>
                  <th style="width:22%">Tipo</th>
                  <th style="width:28%">Equipamento</th>
                  <th style="width:20%">Nº de Série</th>
                  <th style="width:24%">Patrimônio</th>
                </tr>
              </thead>
              <tbody>
                {% for item in items %}
                <tr>
                  <td>{{ loop.index }}</td>
                  <td>{{ item.type_name }}</td>
                  <td>{{ item.name }}</td>
                  <td>{{ item.serial|default('-') }}</td>
                  <td>{{ item.otherserial|default('-') }}</td>
                </tr>
                {% endfor %}
              </tbody>
            </table>

            <div class="terms">
              <h3>Termo e Condições</h3>
              <div class="terms-body">
                <ol>
                  <li>O(A) colaborador(a) declara receber os equipamentos listados em perfeitas condições de uso, comprometendo-se a zelar pela sua integridade e conservação.</li>
                  <li>Os equipamentos destinam-se exclusivamente ao uso profissional, sendo vedada a cessão a terceiros, alteração de configuração ou instalação de softwares não autorizados pela equipe de TI.</li>
                  <li>Em caso de dano, perda, roubo ou furto, o(a) colaborador(a) deverá comunicar imediatamente ao setor responsável, sob pena de responsabilização.</li>
                  <li>Por ocasião do desligamento, troca de função ou solicitação da empresa, todos os equipamentos relacionados neste termo deverão ser devolvidos nas mesmas condições em que foram recebidos.</li>
                  <li>O presente termo passa a vigorar a partir da data de sua assinatura.</li>
                </ol>
              </div>
            </div>

            <div class="signatures">
              <div class="signature-block">
                <div class="td-signature-name" data-td-role="deliverer"></div>
                <div class="line"></div>
                <div class="name">{{ requester.firstname }} {{ requester.realname }}</div>
                <div class="role">Entregador</div>
                <div class="td-signature-date" data-td-role="deliverer"></div>
              </div>
              <div class="signature-block">
                <div class="td-signature-name" data-td-role="recipient"></div>
                <div class="line"></div>
                <div class="name">{{ user.firstname }} {{ user.realname }}</div>
                <div class="role">Recebedor</div>
                <div class="td-signature-date" data-td-role="recipient"></div>
              </div>
            </div>
            HTML;
    }
}
