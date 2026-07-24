# Module boundaries

GN-System is a modular monolith. Each domain module owns its writes and exposes
cross-module reads through contracts in its Application layer.

Allowed dependency direction:

`Presentation -> Application -> Domain`

Infrastructure implements Application or Domain contracts. A module must not
import another module's model or write directly to another module's tables.
Cross-module side effects use application services or domain events.
