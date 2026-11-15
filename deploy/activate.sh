#!/usr/bin/env bash
set -euo pipefail
umask 077

root=${1:?Не задан DEPLOY_ROOT}
state=${2:?Не задан DEPLOY_STATE}
release=${3:?Не задан идентификатор релиза}

# Обновляем только отдельную папку Nova Swim, а не соседние сайты аккаунта.
[[ "$root" =~ ^/[a-zA-Z0-9_./-]+/nova-swim/public_html$ ]] || exit 2
[[ "$state" =~ ^/[a-zA-Z0-9_./-]+/\.deploy/nova-swim$ ]] || exit 2
[[ "$root/$state" != *'/../'* ]] || exit 2
[[ "$release" =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]] || exit 2
[[ -d "$root" && ! -L "$root" ]] || exit 2

mkdir -p "$state/releases" "$state/backups"
chmod 700 "$state" "$state/releases" "$state/backups"
exec 9>"$state/deploy.lock"
flock -w 120 9

archive="$state/incoming/$release.tar.gz"
destination="$state/releases/$release"
backup="$state/backups/$release.tar.gz"
test -f "$archive"
test ! -e "$destination"
mkdir "$destination"
tar -xzf "$archive" --no-same-owner -C "$destination"
test -s "$destination/index.html"
test -s "$destination/mail.php"
test -s "$destination/phpmailer/PHPMailer.php"
test -s "$destination/.htaccess"
test ! -e "$destination/config.php"
test ! -e "$destination/mail-config.php"
test ! -e "$destination/.env"

while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find "$destination" -type f -name '*.php' -print0)

# Сохраняем всю текущую папку, включая заглушку хостинга и cgi-bin.
# Если архивирование не удалось, публикация не начинается.
tar -czf "$backup" -C "$root" .
chmod 600 "$backup"
find "$destination" -type d -exec chmod 755 {} +
find "$destination" -type f -exec chmod 644 {} +
added_files=()
while IFS= read -r -d '' file; do
    relative=${file#"$destination/"}
    if [[ ! -e "$root/$relative" ]]; then added_files+=("$root/$relative"); fi
done < <(find "$destination" -type f -print0)

restore_on_error() {
    result=$?
    trap - ERR
    echo 'Публикация прервана; восстанавливаем прежние файлы из резервной копии.' >&2
    if ((${#added_files[@]})); then rm -f -- "${added_files[@]}"; fi
    tar -xzf "$backup" --no-same-owner -C "$root"
    exit "$result"
}
trap restore_on_error ERR

# Сначала ресурсы, затем обработчик и остальные файлы, главная страница — последней.
# --delete не используется: cgi-bin и файлы хостинга сохраняются.
rsync -rlt --delay-updates --exclude=/index.html "$destination/" "$root/"
rsync -rlt "$destination/index.html" "$root/index.html"
cmp "$destination/index.html" "$root/index.html"
cmp "$destination/mail.php" "$root/mail.php"
printf '%s\n' "${release%%-*}" > "$state/deployed-sha"
printf '%s\n' "$release" > "$state/deployed-release"
trap - ERR
echo "Опубликован релиз $release"
echo "Резервная копия: $backup"
