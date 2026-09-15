# Termodocs

Plugin para **GLPI 11** que gera termos de entrega e devolução de equipamentos, com assinatura eletrônica dos dois lados (colaborador e TI), direto de dentro do GLPI — sem sair para papel, e-mail ou outra ferramenta.

> Requer GLPI `>= 11.0.0`. Licença GPL-3.0-or-later.

---

## O que o plugin resolve

Hoje a entrega e a devolução de equipamentos (notebooks, monitores, periféricos...) normalmente viram um processo informal, sem histórico centralizado e sem vínculo direto com o ativo cadastrado no GLPI. O Termodocs formaliza isso: modelos de documento em HTML, preenchidos automaticamente com os dados do ativo/colaborador/entidade, e uma tela de aceite dentro do próprio GLPI — com efeito real no cadastro (o ativo passa a ficar associado ao colaborador que recebeu, e some da associação quando é devolvido).

---

## Principais características

### 📄 Modelos de documento autocontidos
- Cada modelo já traz **cabeçalho, rodapé, CSS e conteúdo** juntos — sem um sistema separado de "temas" para gerenciar.
- Editor de conteúdo em HTML + [Twig](https://twig.symfony.com/) sandboxed, com um catálogo de campos por tipo de ativo (`{{ equipment.serial }}`, `{{ user.firstname }}`, etc.) e suporte a `{% for item in items %}` para listar múltiplos ativos num mesmo termo.
- Imagem de fundo opcional, permissão de múltiplos ativos por termo, e pré-visualização ao vivo com dados de exemplo antes de salvar.
- Compatível tanto com os ativos clássicos (Computer, Monitor, NetworkEquipment, Peripheral, Printer, Phone) quanto com **Assets customizados** (`AssetDefinition`) do GLPI 11.

### 🔄 Entrega e Devolução como operações de verdade
- Cada modelo é marcado como **Entrega** ou **Devolução** — não é só um rótulo, o wizard usa isso para validar o estado real do ativo:
  - **Entrega**: só deixa selecionar ativos que **não** têm usuário associado.
  - **Devolução**: só deixa selecionar ativos que **já têm** usuário associado, e trava o colaborador da devolução automaticamente para quem de fato está com o equipamento (sem dropdown livre, sem risco de erro).
- Ao ser **totalmente aceito**, o ativo é automaticamente associado ao colaborador (entrega) ou desassociado (devolução) — o vínculo no GLPI acompanha o termo assinado.

### ✍️ Assinatura eletrônica dos dois lados
- Documento só fica **"Aceito"** quando **as duas partes assinam**: o colaborador (recebendo ou devolvendo) e o responsável de TI (entregando ou recebendo de volta).
- Recusa de qualquer uma das partes marca o documento como **"Recusado"** imediatamente, mesmo que a outra já tenha assinado.
- Cada aceite grava nome, data/hora, IP e hash do conteúdo assinado — e o nome de quem assinou aparece **dentro do próprio documento**, em estilo de assinatura manuscrita, junto com a data, no lugar reservado no modelo.
- Tela de aceite funciona tanto na interface central quanto na **simplificada** (self-service), com menu próprio de "Assinaturas pendentes" — visível a qualquer usuário logado, sem precisar de perfil administrativo.

### 🌐 Assinatura via Assinei.digital (opcional)
- Além do aceite interno (padrão), um modelo pode usar **Assinei.digital** como "Modo de assinatura" — nesse caso as duas partes assinam na página hospedada da Assinei.digital (link enviado por e-mail por eles), não dentro do GLPI; a tela de aceite do GLPI mostra apenas o status, sem botões de aceitar/recusar.
- Configuração em **Configurar > Plugins > Termodocs** (ícone de engrenagem): credenciais da API (Subscription-Key, usuário/senha do portal, Tenant ID, Cofre ID) e a URL do webhook a ser repassada para a equipe de operações da Assinei ao pedir a configuração do lado deles.
- `front/webhook.php` recebe os eventos (`document_signed`, `document_refused`, ...), autenticados por um segredo compartilhado na própria URL (gerado automaticamente, renovável na tela de configuração) — não exige sessão GLPI, pois quem chama é o servidor da Assinei.
- Implementado como um provider (`GlpiPlugin\Termodocs\Signature\SignatureProviderInterface`) plugado no `SignatureProviderManager` — arquitetura pensada desde o v1 exatamente para isso, sem alterar `Document`/`DocumentTemplate`/motor de placeholders. Um novo provedor (Clicksign, Autentique...) segue o mesmo padrão.
- ⚠️ O formato exato do payload do webhook (nomes de campo) não é publicado na documentação pública da Assinei além da lista de eventos — `AssineiDigitalProvider::handleCallback()` tenta várias chaves plausíveis e sempre grava o payload bruto em `Document.external_payload`; confirme contra um webhook real do tenant do cliente e ajuste se necessário.

### 🧙 Assistente de geração guiado
- Fluxo em etapas: escolhe a operação (Entrega/Devolução) → seleciona o(s) ativo(s) (já filtrados pelo estado certo) → escolhe modelo, colaborador e responsável de TI → gera.
- Também disponível como ação em massa nas listagens de ativos (Computer, Monitor...) e como aba dentro de cada ativo individual.

### 📤 Exportação e importação
- **Modelos**: exporta todos como um único JSON (HTML, CSS, imagem de fundo embutida) e importa de volta — útil para levar para outra instalação ou como backup. Modelos importados entram desativados, para revisão antes de ativar.
- **Documentos**: exporta o histórico completo (qualquer status) com colaborador/entregador/solicitante identificados por **e-mail** (não por ID interno). Na importação, quem não for identificado por e-mail aparece numa tela de revisão para associação manual antes de confirmar.

### 🔐 Permissões e integridade
- Direitos próprios por área: modelos, documentos e aceites — sensíveis o suficiente para restringir quem pode desenhar um modelo (HTML livre) de quem só gera/assina documentos.
- O HTML gerado (`rendered_html`) é **congelado** no momento da geração, com hash de integridade — nunca é reescrito depois, nem quando a assinatura estilizada é exibida (isso é só uma camada visual por cima, na hora de mostrar).
- Geração de PDF nativa via TCPDF, reaproveitando o GED (`glpi_documents`) já existente do GLPI.

---

## Estrutura do plugin

```
termodocs/
├── ajax/           Endpoints AJAX (catálogo de campos, pré-visualização, seletor de ativos)
├── front/          Controllers (documentos, modelos, aceite, assinaturas pendentes, export/import)
├── install/        Instalação e migrações
├── src/            Classes principais (Document, DocumentTemplate, Acceptance, Placeholder\*, ...)
├── templates/      Views Twig (core GLPI, não confundir com os modelos de documento do usuário)
└── setup.php       Registro do plugin no GLPI
```

## Instalação

1. Coloque a pasta em `plugins/termodocs/` dentro da instalação do GLPI.
2. Em **Configurar > Plugins**, instale e ative o Termodocs.
3. Acesse **Gerência > Termodocs** para criar seus modelos de documento.
