# Comercial + Sidnet

App dos vendedores para cadastrar **Nova venda**, **Upgrade** e **2º plano (mesmo cliente)**.
Funciona sem internet: cada cadastro fica guardado no celular, entra numa fila e é enviado
sozinho ao CRM (crm.sidnet.com.br) quando o celular conecta.

## Como funciona

```
celular (app, funciona offline)  →  servidor do app (Debian)  →  CRM (crm.sidnet.com.br)
   fila no próprio celular            guarda tudo e repassa         Novo Lead / Upgrades
```

1. O vendedor salva o cadastro. No histórico ele fica **Na fila**.
2. Com internet (ao abrir o app, ao conectar e a cada 20 s), o app manda a fila para o servidor do app
   com a chave do vendedor. O selo vira **No servidor**: o cadastro já saiu do celular.
3. A cada minuto o servidor entra no CRM com um usuário do sistema e preenche a tela
   **Novo Lead** (venda e 2º plano) ou **Upgrades → Lançar à mão** (upgrade), do mesmo jeito que uma pessoa faria.
4. O app pergunta a situação e o selo vira **No CRM Lead #123**. Se o CRM recusar (ex.: CPF duplicado),
   o motivo aparece no app e no painel, onde dá para tentar de novo, enviar mesmo assim ou descartar.

Cada cadastro tem um `id_app` único: reenviar não cria venda duplicada.
Sem chave de acesso em Ajustes, o app roda em **modo demonstração** (envio simulado).

## Pastas

| Pasta | O que tem |
|---|---|
| `app/` | A tela do app (`index.html`). É o que vai dentro do APK (pasta `assets/`). |
| `server/` | Servidor do app: API (`public/api/app-vendas.php`), painel (`public/painel/`), repasse ao CRM (`bin/repassar.php`, roda no cron) e instalação (`deploy/install.sh`). PHP 8 + SQLite + nginx. |

## Instalar no Debian

Antes: faça backup do sistema antigo e pare o que estiver usando as portas 80/443 nesse servidor.
Aponte um domínio (ex.: `app.sidnet.com.br`) para o IP do servidor.

```
curl -fsSLO https://raw.githubusercontent.com/sidnettelecom/comercial-sidnet-app/main/server/deploy/install.sh
sudo DOMINIO=app.sidnet.com.br bash install.sh
sudo nano /etc/comercial-sidnet/config.php
```

No `config.php`: usuário e senha do CRM só para o app (crie no CRM um usuário como `app.vendas`,
com acesso ao Novo Lead e aos Upgrades) e a senha do painel. Depois, em `https://app.sidnet.com.br/painel/`:
carregue os vendedores do CRM, crie cada vendedor e passe a chave para ele colocar em Ajustes no app.

Atualizar depois: `sudo git -C /opt/comercial-sidnet pull`.
Log do repasse: `/var/lib/comercial-sidnet/repasse.log`.

## Campos que vão para o CRM

O mapeamento fica no servidor (`server/lib/repasse.php`): se a tela do CRM mudar, corrige-se lá, sem atualizar os celulares.
O servidor lê o formulário do CRM antes de cada envio e mantém os valores padrão dos campos que o app não preenche.

Venda / 2º plano → `novo-lead.php`: empresa_id, data_lead, nome, tipo_pessoa, cpf, whatsapp,
telefone_residencial, email, data_nascimento, logradouro, cidade, uf, referencia, vendedor_id,
origem, plano_interesse, valor (do catálogo), status (Venda Fechada), dia_vencimento, perfil_cliente
(Novo cliente / Cliente atual), tipo_servico (Instalação), observacao.

Upgrade → `servicos-vendedor.php` (lançar à mão): cliente, doc, data, vendedor_id, plano_de,
plano_para, valor_de, valor_para, observacao.

## Pendências

- Primeiro envio de verdade: fazer 1 lead de teste com o usuário do app, conferir no CRM e apagar.
  O repasse foi testado contra uma cópia das telas do CRM, não contra o CRM real.
- Fotos e vídeo continuam indo pelo WhatsApp (o servidor ainda não recebe anexos).
- Gerar o APK novo (versão 2.0) com esta tela e publicar no Navita.
