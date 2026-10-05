# Dashboard para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

**Painéis de indicadores** montados pelo próprio usuário, com dados de **chamados, problemas, mudanças e projetos**. Qualquer métrica pode ser cruzada com qualquer dimensão, e os gráficos usam o ECharts que já vem no GLPI.

## O que o plugin faz

### Painéis
- Criados a partir de **modelos** (visão geral, SLA, técnicos, tendências, lista) ou em branco.
- **Editor de layout:** arrastar para reordenar, ajustar largura e altura, duplicar e remover widgets.
- **Assistente de widget** com pré-visualização.
- **Visibilidade:** privado (só o dono), perfis escolhidos ou todos com direito de ver painéis.
- Duplicar e excluir painéis.

### Widgets
- **Tipos de widget:** indicador, gráfico (barras, barras horizontais, linha, área, pizza, rosca), ranking, **mapa de calor** (hora × dia), comparação de técnicos e lista de itens.
- **17 métricas:**
  - abertos, solucionados, fechados, em aberto, backlog, pendentes, parados há mais de 24 h, SLA estourado, reabertos;
  - SLA de atendimento e de solução;
  - TMA (atendimento), TMR (solução), tempo médio pendente, tempo de ação, tarefas e satisfação.
- **16 dimensões:**
  - tempo (dia, semana ou mês), status, módulo, tipo (incidente ou requisição), prioridade, urgência, categoria, entidade;
  - grupo atribuído, técnico atribuído, requerente, origem da requisição, localização, motivo de pendência, hora do dia e dia da semana.
- **Detalhamento:** clique num valor para ver a lista de itens que formam o número.
- **Ampliar** um widget em tela cheia.

### Filtros
- **Período**, com **comparação** com o período anterior.
- Módulos, entidades, grupos, técnicos, categorias e outros recortes, aplicados **em tempo real**.
- Os filtros ficam no endereço da página, então dá para compartilhar o link ou voltar a ele.
- Os dados respeitam sempre as **entidades ativas** do usuário.

### Exportação e envio
- **PDF**, **PNG** e **CSV** direto do navegador.
- **Enviar por e-mail**: resumo em HTML com os indicadores e os dados de cada widget em tabelas, mais o PDF anexado.
- **Envios agendados** diários, semanais ou mensais, numa hora escolhida e com período próprio. A tarefa automática roda de hora em hora e cada envio fica registrado.

## Configuração e direitos

- **Direitos nativos** na aba **Dashboard** do perfil: ver painéis, criar os próprios e gerenciar todos.
- Opções da página de configuração:
  - grupos considerados técnicos;
  - usuários que ficam fora dos rankings;
  - intervalo de atualização automática;
  - limite de linhas das listas;
  - remetente e rodapé dos e-mails.

O menu fica em **Ferramentas → Dashboard**.

---

## Download e instalação

1. Baixe o arquivo `dashboard-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/dashboard/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/dashboard
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install dashboard -u <usuário administrador>
   php bin/console plugin:activate dashboard
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/dashboard` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install dashboard -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).