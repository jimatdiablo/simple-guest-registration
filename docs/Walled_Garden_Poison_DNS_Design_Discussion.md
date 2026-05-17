# Walled Garden Poison DNS Design Discussion

## Purpose

Simple Guest Registration is intended to operate as a walled garden registration app. An unregistered guest should be guided to the registration page automatically, without needing to know the app URL or call staff for instructions.

One possible approach is to give unregistered guests a short DHCP lease that points their device to a local "poison DNS" service. That DNS service would resolve most web names to the Simple Guest Registration app, causing normal browser activity to land on the registration page.

This document is for peer discussion only. It is not an implementation plan yet.

## Proposed Deployment Model

Because Simple Guest Registration may be deployed many times for different customers, the walled garden DNS function should be bundled with the SGR deployment.

The preferred structure is one Docker Compose stack per customer:

```text
sgr_app
sgr_dns
sgr_db
sgr_scheduler
```

From an operator point of view, this is still one deployable SGR package. Technically, the DNS service should be its own container rather than being built into the PHP web app container.

## Why Use a Separate DNS Container?

A separate DNS container keeps the deployment portable while avoiding unnecessary coupling.

Benefits:

- DNS can restart independently from the web app.
- PHP or Apache changes do not interrupt DNS.
- DNS can expose port 53 cleanly.
- DNS logging and health checks are easier to understand.
- Customer deployments stay consistent and repeatable.

Likely DNS candidates include:

- CoreDNS
- dnsmasq
- PowerDNS Recursor

CoreDNS may be a good fit because its configuration is simple, file-based, and container-friendly.

## Basic Guest Flow

1. Guest connects behind an unregistered modem or CPE.
2. DDNet/provisioning identifies the guest as unregistered.
3. DHCP gives the guest a short lease.
4. DHCP sets the guest DNS server to the SGR DNS service.
5. Guest opens a browser or their device captive portal prompt appears.
6. Poison DNS resolves most names to the SGR app IP address.
7. Guest lands on the Simple Guest Registration page.
8. Guest completes registration.
9. SGR updates provisioning:
   - applies the correct working profile
   - creates or updates the normal DDNet reservation
   - creates the modem-scoped reservation
   - removes stale scoped reservations when appropriate
   - triggers reboot or renewal behavior where possible
10. Guest receives normal service behavior after provisioning completes.

## Poison DNS Behavior

For unregistered guests, DNS would answer most queries with the SGR app address.

Example concept:

```text
www.example.com      -> SGR app IP
www.google.com       -> SGR app IP
random-site.net      -> SGR app IP
registration.local   -> SGR app IP
```

Some domains may need to be allowed through or handled specially, especially if captive portal behavior needs to work well on phones and laptops.

Possible exception categories:

- Apple captive portal checks
- Android captive portal checks
- Windows connectivity checks
- payment processor domains, if payments are ever added
- customer-specific support or policy pages
- internal service names required by the deployment

## Important Network Requirement

The DNS service cannot only exist inside Docker's private network. Guest devices must be able to reach it.

Possible ways to expose DNS:

- Publish UDP/TCP port 53 on the Docker host.
- Run the stack on a VM with an IP reachable from guest networks.
- Use Docker macvlan so the DNS container has a LAN-routable IP.
- Place the SGR host on a routed management or services VLAN reachable from guest networks.

The right choice depends on the customer's network design.

## DNS Alone Is Not a Complete Wall

Poison DNS helps steer normal users to the registration app, but it should not be the only control.

Guests may bypass DNS by using:

- manually configured public DNS
- DNS-over-HTTPS
- cached DNS entries
- apps that do not rely on normal DNS behavior

The network should still enforce walled garden access. Ideally, unregistered guests can reach:

- SGR web app
- SGR DNS service
- required captive portal or allow-list destinations

They should not have general internet access until registration succeeds.

## HTTPS Limitation

Poison DNS works best when the guest opens a plain HTTP site or when the device shows a captive portal prompt.

If a guest opens an HTTPS site, the browser may show a certificate warning because the browser requested one domain but reached the SGR app instead.

This is normal for captive portal systems and cannot be fully avoided with DNS alone.

Ways to reduce confusion:

- Make sure captive portal checks trigger correctly.
- Keep the registration page available over plain HTTP.
- Avoid forcing guests through HTTPS before registration.
- Provide clear staff instructions for guests who open an HTTPS page first.

## Relationship to DDNet and Modem-Scoped Reservations

For unregistered guests, DDNet would issue a short lease and point DNS to the SGR DNS service.

After registration, SGR should update DDNet so the guest receives normal service behavior. That may include:

- normal DNS options
- normal lease duration
- correct bootfile/profile behavior
- modem-scoped reservation for guest-specific DHCP options
- removal of stale modem-scoped reservations when modems are replaced

The modem-scoped reservation remains important because it lets the app apply guest-specific DHCP behavior without changing the entire modem or service group globally.

## Customer Deployment Configuration

Each customer deployment would need configuration values such as:

- SGR app IP or hostname
- SGR DNS IP
- upstream DNS servers
- service groups
- normal DNS servers
- walled garden DNS behavior
- allow-listed domains
- DDNet base URL
- Gunslinger integration settings

These should be environment-driven so one image can support multiple customers.

## Operational Questions To Resolve

Before implementation, we should decide:

1. Should the DNS service be CoreDNS, dnsmasq, or another service?
2. What IP address will guest devices use for DNS?
3. Will DNS be exposed by host port binding, macvlan, or a dedicated VM IP?
4. Which domains should bypass poison DNS?
5. Which captive portal detection domains should be handled specially?
6. How will unregistered guests be blocked from using outside DNS?
7. How will DNS logs be viewed by support staff?
8. Should poison DNS be optional per customer?
9. Should the app show a status page for DNS service health?
10. What exact DDNet DHCP options will be used for the short-lease walled garden state?

## Recommended Direction

Bundle Poison DNS with the Simple Guest Registration deployment as an optional `sgr_dns` service in Docker Compose.

Do not put DNS inside the PHP app container unless there is a strong operational reason. A separate DNS container gives us cleaner restarts, cleaner logs, clearer health checks, and easier per-customer configuration while still preserving the goal of a portable customer deployment.

The initial implementation should be treated as a controlled pilot with one customer or lab service group before using it broadly.

## Lab Pilot Configuration

For the current lab, the bundled DNS service uses dnsmasq and is exposed from the workstation IP:

- DNS server IP for guest DHCP: `192.168.160.4`
- Poison DNS answer target: `192.168.160.4`
- Docker Compose service: `dns`
- Host port binding: `192.168.160.4:53` for UDP and TCP
- SGR HTTP lab URL: `http://192.168.160.4`

The dnsmasq service answers wildcard DNS with the target IP:

```text
address=/#/192.168.160.4
address=/registration.local/192.168.160.4
```

The lab dnsmasq config also filters AAAA responses so IPv6 answers do not bypass the IPv4 poison DNS target during initial testing.

Start and inspect it with:

```bash
docker compose up --build -d dns
docker compose logs -f dns
```

Test from a lab client:

```bash
nslookup example.com 192.168.160.4
```

Because SGR is published on port `80`, poison-DNS-steered plain HTTP requests can land on the registration app without guests typing a port.
