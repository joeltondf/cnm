# Guia para gerar um executável do Chatterbox

Este guia descreve como preparar o projeto [resemble-ai/chatterbox](https://github.com/resemble-ai/chatterbox) localmente e empacotar o aplicativo em um executável usando Node.js e a ferramenta [`pkg`](https://github.com/vercel/pkg). As etapas assumem um ambiente Linux, mas funcionam de forma semelhante no Windows (PowerShell) e macOS (Terminal).

> **Importante:** Como o ambiente desta documentação não possui acesso direto ao repositório remoto, execute os comandos abaixo na sua própria máquina. Ajuste caminhos e versões conforme necessário.

## 1. Clonar o repositório

```bash
git clone https://github.com/resemble-ai/chatterbox.git
cd chatterbox
```

Se a sua rede exigir autenticação por token, substitua a URL por `https://<TOKEN>@github.com/resemble-ai/chatterbox.git`.

## 2. Instalar dependências

O Chatterbox é distribuído como um projeto Node.js. Garanta que você tenha:

- Node.js 18.x ou superior (verifique com `node --version`)
- npm 9.x ou superior (verifique com `npm --version`)

Instale as dependências do projeto:

```bash
npm install
```

> Caso o repositório possua instruções específicas (por exemplo, uso de `pnpm` ou `yarn`), substitua o comando acima pela ferramenta indicada no arquivo `README.md` do projeto.

## 3. Validar scripts existentes

Verifique os scripts disponíveis no `package.json` para confirmar se já existe alguma etapa de build:

```bash
cat package.json | jq '.scripts'
```

Se houver um script `build`, execute-o:

```bash
npm run build
```

Essa etapa garante que qualquer código TypeScript ou assets sejam gerados antes do empacotamento.

## 4. Configurar o `pkg`

Instale a ferramenta globalmente (ou adicione ao `devDependencies`):

```bash
npm install --save-dev pkg
```

Em seguida, adicione uma configuração mínima no `package.json` para indicar o arquivo de entrada do aplicativo. Exemplo:

```json
"pkg": {
  "scripts": "dist/**/*.js",
  "assets": ["public/**/*"],
  "targets": ["node18-linux-x64", "node18-win-x64", "node18-macos-x64"]
}
```

Ajuste o caminho principal (`scripts`) para apontar para o arquivo que inicia o servidor (por exemplo, `dist/index.js` ou `server.js`). Inclua também quaisquer pastas estáticas necessárias.

Adicione um script npm para facilitar o empacotamento:

```json
"scripts": {
  "build": "tsc -p .",
  "package": "pkg . --out-path dist/bin"
}
```

> Se o projeto já estiver em JavaScript puro, remova o comando `tsc -p .` da etapa de build.

## 5. Gerar o executável

Execute o script de empacotamento:

```bash
npm run package
```

Os executáveis serão gerados na pasta `dist/bin/` para cada alvo especificado em `pkg.targets`. Renomeie ou mova os arquivos conforme necessário.

## 6. Testar o binário

```bash
./dist/bin/chatterbox-linux
```

No Windows, execute `dist\bin\chatterbox-win.exe`. Certifique-se de que as variáveis de ambiente usadas pelo projeto (por exemplo, URLs de APIs, chaves, credenciais) estejam configuradas antes de iniciar o binário.

## 7. Resolver dependências nativas

- Se o projeto usar módulos nativos (por exemplo, `sharp`, `sqlite3`), consulte a documentação do `pkg` para compilar as dependências com o mesmo alvo de Node.
- Para serviços que dependem de arquivos externos (templates, assets, `.env`), adicione-os no campo `pkg.assets` ou copie manualmente para a pasta de distribuição.

## 8. Automatizar com Docker (opcional)

Caso deseje encapsular o processo, crie um `Dockerfile` semelhante ao abaixo:

```Dockerfile
FROM node:18 AS builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build && npx pkg . --out-path dist/bin

FROM debian:stable-slim
WORKDIR /opt/chatterbox
COPY --from=builder /app/dist/bin/chatterbox-linux ./chatterbox
CMD ["./chatterbox"]
```

Construa com:

```bash
docker build -t chatterbox-exec .
```

E execute:

```bash
docker run --rm -p 3000:3000 --env-file .env chatterbox-exec
```

## 9. Dicas adicionais

- Mantenha um arquivo `.env.example` atualizado para facilitar a configuração das variáveis.
- Automatize testes (`npm test`) antes de gerar o executável.
- Documente no `README.md` do Chatterbox como atualizar a versão do Node e regenerar os binários.

Seguindo essas etapas você terá um executável multiplataforma do Chatterbox pronto para distribuição.
