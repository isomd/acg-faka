# 启动时派生配置密钥

加密生产部署在容器启动时执行一次 PBKDF2-SHA256（仍为 600000 轮），随后 PHP 请求直接使用派生密钥执行 AES-256-GCM 解密。仓库继续只保存密文；GitHub Actions 的原有 Secret 不变，无需重新加密当前配置。

`deployment/deploy.sh` 自动挂载 `/run/acg-config` 为 1 MiB 的 tmpfs（noexec/nosuid/nodev）。派生密钥仅写入该内存文件系统，不写入镜像文件层或 `/data`，也不会输出到日志。文件归属 root:www-data、权限 0440；目录权限 0750，PHP 可以读取但不能改写。原始口令不再复制给 PHP 用户。

运行时通过镜像内的 `ACG_CONFIG_RUNTIME_KEY_FILE=/run/acg-config/derived-key.json` 读取密钥，因此 HTTP 请求和 `docker exec` 中的 PHP 都使用相同快速路径。配置明文只存在于 PHP 请求内存中，不落盘。

密钥记录绑定密文文件的 SHA-256；密文被替换后必须重启容器重新派生。密钥缺失、损坏、不匹配或 AES-GCM 校验失败时拒绝加载，不回退到每请求执行 PBKDF2。每次容器重启都会重新生成密钥；错误口令或被篡改的密文会阻止启动，生产发布仍保留原有健康检查和回滚机制。

手工运行加密模式的 Docker 容器也必须加上：

```sh
--tmpfs /run/acg-config:rw,noexec,nosuid,nodev,size=1m,mode=0700
```

未启用加密配置的安装模式保持不变。非 Docker 的旧部署若不设置 `ACG_CONFIG_RUNTIME_KEY_FILE`，仍可使用原有口令/64 位十六进制密钥方式；本地加密工具不依赖派生密钥文件。
