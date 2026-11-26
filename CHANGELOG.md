# Changelog

## [1.0.0-beta.29](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.28...v1.0.0-beta.29) (2026-11-26)


### Bug Fixes

* **releases:** report updates before archive inspection ([#157](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/157)) ([25363f1](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/25363f1ce24c434951dc555a5810906de52f47ce))

## [1.0.0-beta.28](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.27...v1.0.0-beta.28) (2026-11-24)


### Bug Fixes

* **client:** consolidate GitHub HTTP, identity, and host shims ([#151](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/151)) ([2ba58fb](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/2ba58fb19ed7f3ed555a23de7e0955e7824370b7))
* harden sensitive params, redirects, and input validation ([#145](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/145)) ([ef76131](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/ef761314fa8d2a7d0ef196a9831f6f441cf1a42d))
* harden webhook rate-limit reuse and release error mapping ([#150](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/150)) ([584265b](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/584265bde75507ebabf46389661d934b0c0b90c6))
* **webhooks:** verify absence before reporting hook 404s ([#148](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/148)) ([953eca3](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/953eca392f1695ee526f129a070b58a33c797b1a))
* **workflow:** harden workflow assistance reliability and tests ([#149](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/149)) ([39d9079](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/39d9079a2943ab8cef8d48752520ddda4549733f))

## [1.0.0-beta.27](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.26...v1.0.0-beta.27) (2026-11-19)


### Features

* **webhooks:** pass the ping repository id to Core ([#138](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/138)) ([36aef23](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/36aef23be15db41345e7478c10b77c84cf3dac66))


### Bug Fixes

* **credentials:** reword token-type messages without prefixes ([#137](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/137)) ([8522e0d](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/8522e0dcee42885e275166e2d543c878197ed578))
* **provider:** do not report a path absent without access proof ([#134](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/134)) ([4844596](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/4844596175c0f474f11344cc1b6a62b38a45677a))
* **releases:** report unsupported filesystem and gone releases ([#132](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/132)) ([d448d79](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/d448d79b41480ce1bb9feb4d7a864382730f2fbc))
* **security:** mark private token-carrying parameters sensitive ([#130](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/130)) ([cfa7afa](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/cfa7afaa630b27e379e3ad4504e25f64f1f4ec6c))
* **webhooks:** accept the repo scope and document webhook scopes ([#139](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/139)) ([c1f50e5](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/c1f50e5ec3bbdcc31a6c8f3f7c69898c12eaf172))
* **webhooks:** do not report an unproven hook 404 as absence ([#131](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/131)) ([f060288](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/f060288104f99ba9bd2e45a9a692ab52fa77a41b))
* **webhooks:** fail set-up cleanly before any create request ([#140](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/140)) ([bc54831](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/bc5483174cee1511576ce8e692c3217b69e2a255))
* **webhooks:** report failed requests by what they prove ([#136](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/136)) ([0bc27f7](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/0bc27f7c1006b8b0f66f7af9b7cc4e6386041c34))
* **workflow:** report a credential store failure as unavailable ([#135](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/135)) ([ff7b6bf](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/ff7b6bf0a9a4cacc26aae16a9033a0498ab3cf3d))

## [1.0.0-beta.26](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.25...v1.0.0-beta.26) (2026-11-18)


### Bug Fixes

* distinguish missing revisions on manual branch checks ([#128](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/128)) ([d828149](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/d828149e1575cf6f754795d7b958f36c49aa8b72))

## [1.0.0-beta.25](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.24...v1.0.0-beta.25) (2026-11-17)


### Features

* carry screened provider source codes ([#126](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/126)) ([74b1c9b](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/74b1c9b9a5694bbcebacb4d617173e256823b23c))

## [1.0.0-beta.24](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.23...v1.0.0-beta.24) (2026-11-17)


### Features

* forward release listing reports and digest details ([#125](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/125)) ([fd34942](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/fd3494299730ae8a7350dece51a036a9cb2caeb1))
* **github:** populate browse and credential failure reasons ([#122](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/122)) ([00d4e2b](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/00d4e2b27b39aed18241f6c6b96a6edb01f58ba0))


### Bug Fixes

* adopt release updater beta.21 and Support beta.9 ([#124](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/124)) ([4f44194](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/4f44194dae94f520f17d0b68c38857d00effad4b))
* **github:** distinguish missing branch and access outcomes ([#120](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/120)) ([d1462fd](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/d1462fdea95e94bb330b5f3866abb82fe71fde92))
* **releases:** preserve release listing failure outcomes ([#123](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/123)) ([3f29b7a](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/3f29b7af655feb27b6a8c202f9f4135cfb54c10c))

## [1.0.0-beta.23](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.22...v1.0.0-beta.23) (2026-11-16)


### Features

* **github:** carry anonymous lookup rate-limit facts ([a345abf](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/a345abff2a9513414fe350032cf1bace6bc63918))
* **github:** carry anonymous lookup rate-limit facts ([#118](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/118)) ([113174b](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/113174b9728f793afb4a1653c99bea5daf3f82f4))

## [1.0.0-beta.22](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.21...v1.0.0-beta.22) (2026-11-13)


### Features

* **releases:** carry the exact release rejection reason ([#102](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/102)) ([936d50e](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/936d50ef029627a39a2f935265a671f8e0a2d9a6))

## [1.0.0-beta.21](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.20...v1.0.0-beta.21) (2026-11-04)


### Bug Fixes

* verify durable workflow state deletion against the database ([#99](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/99)) ([b29388e](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/b29388e66ec091ca7634d44697f375ed8ab2d21e))

## [1.0.0-beta.20](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.19...v1.0.0-beta.20) (2026-10-23)


### Features

* **provider:** own GitHub release and webhook guidance ([#94](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/94)) ([c389c13](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/c389c1305952d62ea803c983baef94fe3b990941))

## [1.0.0-beta.19](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.18...v1.0.0-beta.19) (2026-10-22)


### Features

* isolate GitHub workflow state by namespace ([#92](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/92)) ([073e765](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/073e7658baebe50bad040612eac37c9268ec4806))


### Bug Fixes

* reject unsupported webhook branch references ([#91](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/91)) ([be141dc](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/be141dc9bdca1659d319bd6b2fa85a69352a5e3d))

## [1.0.0-beta.18](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.17...v1.0.0-beta.18) (2026-10-16)


### Features

* deliver replacement contracts and historical archives with protocol 6 ([#86](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/86)) ([3fb2c46](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/3fb2c46775baaa640374350a763958be0da61548))

## [1.0.0-beta.17](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.16...v1.0.0-beta.17) (2026-10-14)


### ⚠ BREAKING CHANGES

* integrate qualified workflow verification and setup read reuse ([#81](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/81))

### Performance

* integrate qualified workflow verification and setup read reuse ([#81](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/81)) ([f96db41](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/f96db41d01890841fc84b944b61e422682140e70))

## [1.0.0-beta.16](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.15...v1.0.0-beta.16) (2026-10-12)


### Features

* use Core activity history for GitHub workflow checks ([#72](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/72)) ([4399780](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/43997801a6392dce13c74ce29325428384d49c26))

## [1.0.0-beta.15](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.14...v1.0.0-beta.15) (2026-10-09)


### Bug Fixes

* enforce PHPStan Level 8 across maintained Provider profiles ([#70](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/70)) ([59d02a4](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/59d02a41935518196f7a066748dfc9195eba27ec))

## [1.0.0-beta.14](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.13...v1.0.0-beta.14) (2026-10-07)


### Bug Fixes

* enforce Provider standards across maintained PHP ([#58](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/58)) ([13386bd](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/13386bd44fe0bb97830028c92f44d4ca4d76ba1d))
* **quality:** integrate maintained PHP quality stack ([#65](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/65)) ([db90f3c](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/db90f3c470bd4149b5931b119aa62e43c7ff59ec))

## [1.0.0-beta.13](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.12...v1.0.0-beta.13) (2026-10-03)


### Bug Fixes

* resolve level-five provider analysis findings ([#55](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/55)) ([fc2b2ef](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/fc2b2efceb847d8816c0b5f6c3dedffdf6eb8009))

## [1.0.0-beta.12](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.11...v1.0.0-beta.12) (2026-10-02)


### ⚠ BREAKING CHANGES

* complete recovered beta.31 naming consumers ([#53](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/53))

### Bug Fixes

* complete recovered beta.31 naming consumers ([#53](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/53)) ([4a40311](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/4a403117dc7a5963fca558c7bba979caaa546c41))

## [1.0.0-beta.11](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.10...v1.0.0-beta.11) (2026-10-01)


### Bug Fixes

* adopt Release Updater beta.9 and runtime protocol 5 ([#51](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/51)) ([d7899c6](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/d7899c6276499325757849b79c68a0545b68bc45))

## [1.0.0-beta.10](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.9...v1.0.0-beta.10) (2026-10-01)


### ⚠ BREAKING CHANGES

* implement Core Provider API 13 method naming ([#49](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/49))

### Code Refactoring

* implement Core Provider API 13 method naming ([#49](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/49)) ([4f17c76](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/4f17c76a25251c19ba65b3987a1b184ee3037ea8))

## [1.0.0-beta.9](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.8...v1.0.0-beta.9) (2026-10-01)


### ⚠ BREAKING CHANGES

* migrate Provider-owned helper APIs with paired Core consumers ([#48](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/48))

### Bug Fixes

* complete local test naming and standards enforcement ([#46](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/46)) ([7f6a064](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/7f6a064664b4730d81eeb2cdaff7e5aaa8244e8c))
* migrate and enforce private workflow naming ([#45](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/45)) ([29917ab](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/29917abc5fc25eda2c1d3746fb2314f03bd93f19))
* migrate and enforce Provider-owned private naming ([#42](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/42)) ([c6b9240](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/c6b924090243a0c0111ffd14c7ebe808ae003dc3))


### Code Refactoring

* migrate Provider-owned helper APIs with paired Core consumers ([#48](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/48)) ([57f1dae](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/57f1dae2c98da9570f6acc27d691a7f03851436a))

## [1.0.0-beta.8](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.7...v1.0.0-beta.8) (2026-09-30)


### Bug Fixes

* preserve bootstrap status across source revisions ([#35](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/35)) ([a849906](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/a849906c9041d5901b84b6f90eccd2a4d808f268))

## [1.0.0-beta.7](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.6...v1.0.0-beta.7) (2026-09-30)


### Features

* complete initial-only API 3 starter consumer ([1fcae86](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/1fcae8676d008e93ce2cf664d34b4c064459e3ce))
* implement the API 3 initial release starter consumer ([#30](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/30)) ([d9f18a1](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/d9f18a10d593d88d0373e377197553c02ee664f2))


### Compatibility

* require RAN Booster Provider API 12 and initial-only workflow API V3; API 11 / V2 hosts are unsupported

### Bug Fixes

* align discovery tags and prove octet-stream setup ([85d594a](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/85d594a52a33fad549af45c9e2a48a4ba73185a9))
* align starter admission with verified release output ([39b761c](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/39b761cefb600ee2dc1d7e55c30026fc0dd88d91))
* align starter readiness and guidance with generated workflows ([ba0a8a5](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/ba0a8a5e0cef1fc3da6ec1b24e4f57cde19ee66f))
* bound anonymous source snapshot requests ([0518c09](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/0518c093fa7fc67be4e4153af91251eb2a23c14e))
* honor advisory overlap and align starter admission ([ebf0f86](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/ebf0f86b1b7fec58193b70c3f9e9851e26da3665))
* reserve generated tree capacity before setup ([22ce323](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/22ce323413ffffe0633739f7e2b0a6b04b304471))
* reserve readback capacity and consume exact producer artifact ([551dd3b](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/551dd3b1b250b7c66ceaad6f42fd43f46ffc60c4))
* verify executable producer and consumer starter convergence ([c90baf5](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/c90baf521e121afe3ef9ad176e4facd2a4a07a93))

## [1.0.0-beta.6](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v1.0.0-beta.5...v1.0.0-beta.6) (2026-09-27)


### Bug Fixes

* align shared Support dependency with updater suite ([#31](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/31)) ([e083560](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/e083560d6b48c00c1e664f98e47bcd418b3674f0))
* **release:** accept semver prerelease major bumps ([f4ca30b](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/f4ca30b2bb5e111e5dea789c8cc94f77aef878a7))
* **release:** add bounded trusted replay candidate ([7b36182](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/7b3618260629250640e28f4ef9261422f46a2d06))
* **release:** compare prerelease components exactly ([ce64c3f](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/ce64c3f58cd0f90e13538c45afb7483748f53fd8))
* **release:** harden replay admission guards ([3e5d503](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/3e5d50359edd9ab9332d0cf0216c5e0b4aecb11a))
* **release:** scope replay to one admitted revision ([9195380](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/91953809d11e097e5a4e90ec283f24f88757bfef))

## [1.0.0-beta.5](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v0.1.0-beta.5...v1.0.0-beta.5) (2026-09-21)


### ⚠ BREAKING CHANGES

* **provider:** recertify GitHub provider for Provider API 11 ([#20](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/20))

### Bug Fixes

* **provider:** recertify GitHub provider for Provider API 11 ([#20](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/20)) ([dc2fb34](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/dc2fb34ae002d369ca9a21de7c0669ed5d98902f))

## [0.1.0-beta.5](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v0.1.0-beta.4...v0.1.0-beta.5) (2026-09-19)


### Bug Fixes

* advance release updater baseline ([#18](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/18)) ([eeab491](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/eeab491f17ffab0441b157ba56fe9da70a869377))

## [0.1.0-beta.4](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v0.1.0-beta.3...v0.1.0-beta.4) (2026-09-18)


### Bug Fixes

* remove prerelease workflow state compatibility ([#16](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/16)) ([bf62fb7](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/bf62fb76a341e923090412b349ed9ff841ae9873))

## [0.1.0-beta.3](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v0.1.0-beta.2...v0.1.0-beta.3) (2026-09-18)


### Bug Fixes

* own workflow-assistance state namespace ([#12](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/12)) ([dadb846](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/dadb8469c963d947c3a8a7069ed231bb4acd4678))

## [0.1.0-beta.2](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/compare/v0.1.0-beta.1...v0.1.0-beta.2) (2026-09-17)


### Bug Fixes

* remove obsolete Assisted Hooks compatibility ([#10](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/10)) ([af28177](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/af281776eae67f24a94812312c9286a80ba03a0f))

## 0.1.0-beta.1 (2026-09-17)


### Features

* **release:** enable trusted provider beta publishing ([#6](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/issues/6)) ([f5e9e23](https://github.com/RocketsAreNostalgic/ran-booster-github-provider/commit/f5e9e2350752972edb0aee54510fa83f1e9cb54b))

## [Unreleased]

All notable changes to this package are documented here.
