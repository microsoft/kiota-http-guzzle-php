# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

### Changed

## [1.3.1] - 2024-09-04

### Changed

- Align OTEL http attributes with latest standard

## [1.2.0]

### Changed

- Bumped minimum required Guzzle HTTP version to secure version

## [1.1.0]

### Added

- Adds Headers Inspection Handler to expose raw request and response headers

### Changed

- Support 2xx range responses where response bodies are not present in the payload.
- Exclude non-prod files from shipped archive

## [1.0.0]

### Changed

- Bump Abstractions to 1.0.0
- Release stable

## [0.9.0] - 2023-10-30

### Added

- Adds generics to Promise types in PHPDocs

## [0.8.4] - 2023-10-11

### Added

- Adds CHANGELOG. [#84](https://github.com/microsoft/kiota-http-guzzle-php/pull/84)

### Changed

- Fix issue with deserialization of error response. [#83](https://github.com/microsoft/kiota-http-guzzle-php/pull/83)
- Ensure only query parameter names are decoded in `parameterNamesDecodingHandler`. [#82](https://github.com/microsoft/kiota-http-guzzle-php/pull/82)

## [0.8.3] - 2023-10-05

### Added

- Adds missing fabric bot configuration. [#74](https://github.com/microsoft/kiota-http-guzzle-php/pull/74)
- Support for tracing. [#71](https://github.com/microsoft/kiota-http-guzzle-php/pull/71)
- Add tryAdd to RequestHeaders. [#81](https://github.com/microsoft/kiota-abstractions-php/pull/81)

### Changed

- Change Exception from UriException to InvalidArgumentException. [#79](https://github.com/microsoft/kiota-http-guzzle-php/pull/79)

## [0.8.2] - 2023-07-07

### Added

- Handle enum deserialization in `sendPrimitiveAsync`. [#69](https://github.com/microsoft/kiota-http-guzzle-php/pull/69)

## [0.8.1] - 2023-06-30

### Changed

- Disable pipeline runs for forks. [#62](https://github.com/microsoft/kiota-http-guzzle-php/pull/62)
- Update microsoft/kiota-abstractions requirement from `^0.7.0 to ^0.7.0 || ^0.8.0`. [#65](https://github.com/microsoft/kiota-http-guzzle-php/pull/65)

## [0.8.0] - 2023-05-18

### Changed

- Bump abstractions. [#57](https://github.com/microsoft/kiota-http-guzzle-php/pull/57)

## [0.7.0] - 2023-05-18

### Added

- Add response headers to Exception on ApiException. [#52](https://github.com/microsoft/kiota-http-guzzle-php/pull/52)
- Retry failed responses with CAE challenge once. [#42](https://github.com/microsoft/kiota-http-guzzle-php/pull/42)

### Changed

- Use Guzzle client lib directly. [](https://github.com/microsoft/kiota-http-guzzle-php/pull/53)
- Accept Guzzle client interface instead of concrete implementation. [#54](https://github.com/microsoft/kiota-http-guzzle-php/pull/54)

## [0.6.3] - 2023-05-04

### Added

- Add middleware tests and re-order handler stack. [#48](https://github.com/microsoft/kiota-http-guzzle-php/pull/48)
- Add UrlReplace Handler middleware. [#49](https://github.com/microsoft/kiota-http-guzzle-php/pull/49)

## [0.6.2] - 2023-03-22

### Changed

- Bump PHPstan to level 9. [#38](https://github.com/microsoft/kiota-http-guzzle-php/pull/38)
- Use generic PHPDoc type for error mapping variables. [#40](https://github.com/microsoft/kiota-http-guzzle-php/pull/40)

## [0.6.1] - 2023-03-07

### Changed

- Update abstractions dependency

## [0.6.0] - 2023-03-07

### Added

- Adds dependabot auto-merge and conflicts workflows. [#30](https://github.com/microsoft/kiota-http-guzzle-php/pull/30)
- Add test matrix for supported PHP versions. [#28](https://github.com/microsoft/kiota-http-guzzle-php/pull/28)
- Add coverage reporting. [#31](https://github.com/microsoft/kiota-http-guzzle-php/pull/31)

### Changed

- Fix bug in calculating chaos percentage. [#29](https://github.com/microsoft/kiota-http-guzzle-php/pull/29)

**For previous releases, please see our [Release Notes](https://github.com/microsoft/kiota-http-guzzle-php/releases)*
