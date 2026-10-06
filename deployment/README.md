# GitHub Actions 生产部署

此流水线使用 Docker Hub 保存镜像。GitHub Actions 在推送版本标签（例如 `1.0.0`）或手动触发时构建并部署镜像，用 `docker/login-action@v3` 的账号密码方式登录并推送。版本发布同时生成版本标签和 `sha-<commit>` 镜像标签，服务器使用 SHA 标签部署。服务器用账号密码 SSH 登录后直接执行 `docker pull`，所以 `DOCKER_REPO/acg-faka` 必须是 **公开仓库**。MySQL 和 Redis 由你单独准备，不在这条流水线中创建或删除。应用数据固定挂载到服务器的 `/srv/acg-faka/data`（可通过仓库变量 `DEPLOY_BASE` 调整）。

## 首次准备

1. 服务器安装 Docker Engine，部署用户能执行 `docker`，并有权写入部署目录。服务器能访问 Docker Hub、MySQL 和可选的 Redis。Docker Hub 创建公开仓库 `<DOCKER_REPO>/acg-faka`，这样服务器可以直接 `docker pull`，无需保存 Docker Hub 登录凭证。
2. 准备独立、空的 MySQL 8.0 数据库和应用账号。安装 SQL 会重建同前缀表，不要复用已有业务库。若 MySQL 运行在同一宿主机，可在加密配置中使用 `host.docker.internal`；MySQL 必须监听 Docker 网桥可访问的地址。不要把 MySQL 或 Redis 端口开放公网。服务器还需要 `curl` 供部署后健康检查使用。
3. 在**仓库之外**创建生产配置 JSON，可参照 `deployment/config.example.json`。Redis 不使用时填 `"enabled": false`。配置文件中的密码只在本地明文文件中出现，完成加密后妥善删除该临时文件。
4. 在本机使用 PHP CLI：

   ```text
   php tools/deployment-config.php new-key <仓库外的密钥文件>
   php tools/deployment-config.php encrypt <仓库外的生产配置.json> <密钥文件> deployment/database.enc.json
   ```

   `new-key` 只运行一次。同一把密钥的十六进制文本保存到 GitHub Environment `production` 的 `ACG_CONFIG_KEY_HEX` Secret。只提交 `deployment/database.enc.json` 密文，不提交明文 JSON 或密钥文件。以后修改数据库或 Redis 设置，用同一密钥重新加密，并替换旧密文文件。

5. 为服务器准备 SSH 账号密码登录。该账号需要能执行 Docker 命令并写入部署目录；如果使用 root，`PROD_SERVER_USER` 填 `root`。本流水线按要求关闭 SSH 主机密钥校验，建议将生产环境设置为受保护的 GitHub Environment。
6. 在 GitHub 仓库中创建下表的 Secrets 和可选 Variables。提交代码和密文后，推送版本标签或手动运行“构建并部署 acg-faka”。仓库级 Secrets 同样可用，无需在 `production` Environment 中重复创建。

## GitHub Secrets

| 名称 | 内容 |
|---|---|
| `DOCKER_USERNAME` | Docker Hub 登录账号，与参考流水线同名 |
| `DOCKER_PASSWORD` | Docker Hub 登录密码或 Access Token，用于 Actions 推送镜像；推荐 Access Token |
| `DOCKER_REPO` | Docker Hub 仓库命名空间，例如 `myuser`，完整镜像名为 `myuser/acg-faka` |
| `PROD_SERVER_ADDRESS` | 目标服务器公网 IP 或主机名 |
| `PROD_SERVER_PORT` | SSH 端口，例如 `22` |
| `PROD_SERVER_USER` | SSH 登录账号，需能使用 Docker 并写部署目录 |
| `PROD_SERVER_PWD` | SSH 登录密码 |
| `ACG_CONFIG_KEY_HEX` | 本地加密时使用的密钥：64 位十六进制值，或至少 12 字节的单行口令（名称保留兼容） |

`GITHUB_TOKEN` 由 GitHub 自动提供；本流水线使用 Docker Hub，不需要单独配置 GHCR Token。服务器不执行 `docker login`，所以不需要服务器端 Docker Hub Token。数据库密码和 Redis 密码已经在密文配置中，不需要再建同名 GitHub Secret。不要将 Mercury App Secret 写进部署 JSON；支付配置在站点后台填写并保存在 MySQL 中。

## 可选 GitHub Variables

| 名称 | 默认值 | 用途 |
|---|---|---|
| `DEPLOY_BASE` | `/srv/acg-faka` | 服务器上的持久化根目录 |
| `ACG_HTTP_PORT` | `80` | 服务器对外 HTTP 端口 |

## 数据与更新

若构建成功后 SSH 部署中断，可手动运行“构建并部署 acg-faka”，在 `deploy_existing_sha` 中填写原发布提交的完整 SHA。流水线会跳过构建，核对已发布镜像后重新部署。SSH 使用保活消息避免长时间拉取镜像时空闲连接被断开。

- `$DEPLOY_BASE/data` 由服务器保存，流水线不会上传或覆盖这个目录。里面有 `config`、`install`、`assets_cache`、`plugins`、`pay`、`themes`、`runtime`，以及使用容器内置服务时才会出现的 `mysql`、`redis`。当前生产流水线显式使用外部数据库模式，不启动容器内置 MySQL/Redis。
- 镜像包含加密 JSON；解密密钥只在服务器的 `$DEPLOY_BASE/secrets` 下保存，并在容器启动时复制到容器临时层。密钥不进入 Git 仓库或镜像层。
- 流水线只替换名为 `acg-faka-app` 的应用容器。旧容器保留为 `acg-faka-previous`。新容器的 HTTP `/healthz` 和数据库连接检查失败时，脚本恢复旧容器。
- 发布前备份外部 MySQL 和 `$DEPLOY_BASE/data`。代码更新不会自动迁移业务数据；涉及表结构变更时应先验证迁移和回滚方案。
- 首次启动后访问服务器 IP 完成安装向导。数据库信息由服务器在内存中解密供向导使用，安装向导不会回写明文 `config/database.php`。
- 没有公网 HTTPS 时，网站可以经 IP 用 HTTP 打开，但 Mercury 正式支付回调地址是否接受纯 HTTP IP 仍需在支付平台确认。

## 轮换密钥

也支持使用口令加密：把口令放在仓库外的密钥文件中，执行同一个 `encrypt` 命令，并把完全相同的口令保存到 `ACG_CONFIG_KEY_HEX`。口令模式使用随机盐和 PBKDF2-SHA256（600000 次）派生 AES-256-GCM 密钥；盐随密文保存，口令不写入仓库。

生成新密钥、重新加密生产配置并更新 `ACG_CONFIG_KEY_HEX` Secret 后再发布。旧容器绑定旧密钥文件，新容器绑定新密钥文件，回滚仍可使用旧密钥。确认无需回滚后，再清理服务器 `$DEPLOY_BASE/secrets` 中不再使用的旧密钥文件。
