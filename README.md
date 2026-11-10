# Comercial + Sidnet

App dos vendedores para cadastrar **Nova venda**, **Upgrade** e **2º plano (mesmo cliente)**.
Funciona sem internet: cada cadastro fica guardado no celular, entra numa fila e é enviado
sozinho ao CRM (crm.sidnet.com.br) quando o celular conecta.

## Pastas

| Pasta | O que tem |
|---|---|
| `app/` | A tela do app (`index.html`). É o que vai dentro do APK (pasta `assets/`). |
| `crm-api/` | Rascunho do endpoint `api/app-vendas.php`, que fica no CRM e recebe os cadastros. |

## Como o envio funciona

1. O vendedor salva o cadastro. Ele vai para o histórico com o selo **Na fila**.
2. Quando há internet (ao abrir o app, ao conectar e a cada 20 s), o app manda a fila,
   um por vez, para `POST /api/app-vendas.php?acao=enviar`, com a chave do vendedor.
3. O CRM grava no **Novo Lead** (venda e 2º plano) ou em **Upgrades → lançar à mão** (upgrade)
   e responde com o número. O selo vira **No CRM Lead #123**.
4. Se der erro, o selo vira **Erro no envio** com o motivo dado pelo CRM. O app tenta de novo até 5 vezes.
   Cada cadastro tem um `id_app` único, então um reenvio não cria venda duplicada.

Sem chave de acesso em Ajustes, o app roda em **modo demonstração** (envio simulado).

## Campos enviados

Venda / 2º plano → `novo-lead.php`: empresa_id, data_lead, nome, tipo_pessoa, cpf, whatsapp,
telefone_residencial, email, data_nascimento, logradouro, cidade, uf, referencia, vendedor_id,
origem, plano_interesse, valor, status (Venda Fechada), dia_vencimento, perfil_cliente
(Novo cliente / Cliente atual), tipo_servico (Instalação), observacao.

Upgrade → `servicos-vendedor.php` (lançar à mão): cliente, doc, data, vendedor_id, plano_de,
plano_para, valor_de, valor_para, observacao.

## Pendências

- Ligar `salvar_lead()` e `salvar_upgrade()` do endpoint ao código que o CRM já usa para gravar.
- Tela "Entrar" no app (usuário e senha do CRM uma vez → recebe a chave).
- Puxar os planos do catálogo do CRM (`?acao=planos`).
- Gerar o APK novo (versão 2.0) com esta tela e publicar no Navita.
