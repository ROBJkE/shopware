#!/usr/bin/env sh
set -eu

if [ -n "${DEBUG:-}" ]; then
  set -x
fi

GITLAB_TOKEN="${GITLAB_TOKEN:-$CI_JOB_TOKEN}"
CI_JOB_TOKEN="${CI_JOB_TOKEN}"
CI_CURRENT_MAJOR_ALIAS="${CI_CURRENT_MAJOR_ALIAS:-}"

# deployment_branch_name returns the branch name for the current deployment.
deployment_branch_name() {
  local from_date="${CI_PIPELINE_CREATED_AT:-now}"

  date --utc --date="${from_date}" +'saas/%Y/%W'
}

# current_major_alias Fetches the latest released version of Shopware 6,
# excluding rc-versions and formats it as a major alias, e.g. `6.6.x-dev`.
#
# Can be overriden by setting the `CI_CURRENT_MAJOR_ALIAS` environment variable.
current_major_alias() {
  if [ -n "${CI_CURRENT_MAJOR_ALIAS}" ]; then
    printf "%s" "${CI_CURRENT_MAJOR_ALIAS}"
    return
  fi

  curl -fsSL "https://releases.shopware.com/changelog/index.json" |
    jq -r '[.[] | select(test("[a-zA-Z]") | not)] | first | split(".") | [.[0], .[1], "x-dev"] | join(".")'
}

# custom_version_core returns the custom version for the core repositories.
custom_version_core() {
  local branch="$(deployment_branch_name)"
  local major_alias="$(current_major_alias)"

  printf "shopware/platform:dev-%s as %s;shopware/commercial:dev-%s;swag/saas-rufus:dev-%s" "${branch}" "${major_alias}" "${branch}" "${branch}"
}

# custom_version_extensions returns the custom version for the extension
# repositories.
custom_version_extensions() {
  set -eu
  local tmpdir="$(mktemp -d)"

  git clone --depth=1 "https://gitlab-ci-token:${CI_JOB_TOKEN}@gitlab.shopware.com/shopware/6/product/saas.git" "${tmpdir}"
  composer -d "${tmpdir}" show --locked --outdated --direct --format=json >"${tmpdir}/outdated.json"

  jq -r \
    '[.locked[] | select(.name | test("^(shopware|swag)/")) | select(.latest | test("(^dev-|-dev)") | not) | select(."latest-status" | test("update-possible|semver-safe-update")) | .name + ":" + .latest] | join(";")' \
    "${tmpdir}/outdated.json"
}

commit_date() {
  local project="${1}"
  local branch="${2}"

  export GITLAB_HOST="gitlab.shopware.com"

  glab api "projects/${project}/repository/commits/${branch}" | jq -r '.committed_date' | xargs -I '{}' date -R --date="{}"
}

get_latest_pipeline_web_url() {
    local project_path="${1}"
    local branch="${2}"

    if [ "${branch}" = "" ]; then
        return 1
    fi

    curl -sSf -X GET -H "Private-Token: ${CI_GITLAB_API_TOKEN}" -H "Content-Type: text/plain" \
        "${CI_API_V4_URL}/projects/${project_path}/pipelines/latest?ref=${branch}" \
        | jq .web_url -r
}

gitlab_mr_description() {
  local deployment_branch_name="$(deployment_branch_name)"
  local deployment_branch_name_url_encoded=$(echo "${deployment_branch_name}" | sed 's/\//%2F/g')

  cat <<EOF | tr -d '\n'
<p>
This MR has been created automatically to facilitate the deployment <em>${deployment_branch_name}</em>.
<br/>
Please review the changes and merge this MR if you are satisfied with the deployment.
</p>
<p>
For the core dependencies, the dates of the latest commits on the branches are as follows, please make sure that all pipelines are green! 👀
<ul>
<li><span>shopware/platform: <b>$(commit_date "shopware%2F6%2Fproduct%2Fplatform" "${deployment_branch_name_url_encoded}")</b>(Pipeline: $(get_latest_pipeline_web_url "shopware%2F6%2Fproduct%2Fplatform" "${deployment_branch_name_url_encoded}"))</span></li>
<li><span>shopware/commercial: <b>$(commit_date "shopware%2F6%2Fproduct%2Fcommercial" "${deployment_branch_name_url_encoded}")</b>(Pipeline: $(get_latest_pipeline_web_url "shopware%2F6%2Fproduct%2Fcommercial" "${deployment_branch_name_url_encoded}"))</span></li>
<li><span>swag/saas-rufus: <b>$(commit_date "shopware%2F6%2Fproduct%2Frufus" "${deployment_branch_name_url_encoded}")</b>(Pipeline: $(get_latest_pipeline_web_url "shopware%2F6%2Fproduct%2Frufus" "${deployment_branch_name_url_encoded}"))</span></li>
</ul>
</p>
<hr/>
EOF
}

# deployment_env compiles the environment variables for the deployment.
deployment_env() {
  local update_extensions="${1:-}"

  local deployment_branch_name="$(deployment_branch_name)"
  local ci_update_dependency="1"
  local gitlab_mr_description="$(gitlab_mr_description)"
  local custom_version=""
  local gitlab_mr_title="Deployment - ${deployment_branch_name}"
  local gitlab_mr_labels="pipeline:autodeploy"
  local gitlab_mr_assignees="shopwarebot"

  if [ -n "${update_extensions}" ]; then
    custom_version="$(custom_version_core);$(custom_version_extensions)"
  else
    custom_version="$(custom_version_core)"
  fi

  cat <<EOF
DEPLOYMENT_BRANCH_NAME="${deployment_branch_name}"
CI_UPDATE_DEPENDENCY="${ci_update_dependency}"
CUSTOM_VERSION="${custom_version}"
GITLAB_MR_TITLE="${gitlab_mr_title}"
GITLAB_MR_DESCRIPTION_TEXT="${gitlab_mr_description}"
GITLAB_MR_LABELS="${gitlab_mr_labels}"
GITLAB_MR_ASSIGNEES="${gitlab_mr_assignees}"
EOF
}

deployment_env_b64() {
  local target_var="${1}"
  local update_extensions="${2:-}"

  printf "%s=%s\n" "${target_var}" "$(deployment_env ${update_extensions} | base64 -w0)"
  printf "CUSTOM_VERSION=1\n"
  printf "CI_UPDATE_DEPENDENCY=1\n"
}

"$@"
