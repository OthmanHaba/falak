# Simulated Kiln-managed server: Ubuntu 24.04 with systemd as PID 1 and sshd.
# Run privileged with its own cgroup namespace (see compose.yml). The agent binary is
# NOT baked in: the repo is mounted read-only at /opt/kiln/src and, at boot,
# kiln-sim-agent-link.service symlinks agent/bin/kiln-agent-linux-$SIM_AGENT_ARCH to
# /usr/local/bin/kiln-agent if it exists. The server boots fine without it.
FROM ubuntu:noble-20260911

ENV container=docker \
    DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      systemd systemd-sysv dbus openssh-server sudo ca-certificates curl iproute2 \
      iputils-ping nftables less procps tzdata \
 # Redis comes preinstalled so the Redis E2E stage doesn't wait for apt; its stock 6379 service stays off until a
 # server's stack asks for it (provisioning starts it then). Not valkey-server as well: noble has it (noble-updates),
 # but the machine check refuses two cache engines on one machine, by design.
      redis-server redis-tools \
 && systemctl disable redis-server.service \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 # Units that make no sense (or fail) inside a container.
 && systemctl mask \
      getty.target console-getty.service serial-getty@.service \
      systemd-udevd.service systemd-udevd-control.socket systemd-udevd-kernel.socket systemd-udev-trigger.service \
      systemd-modules-load.service systemd-remount-fs.service systemd-firstboot.service \
      sys-kernel-config.mount sys-kernel-debug.mount sys-kernel-tracing.mount sys-fs-fuse-connections.mount \
      systemd-networkd-wait-online.service systemd-timesyncd.service systemd-resolved.service \
      e2scrub_reap.service e2scrub_all.timer apt-daily.timer apt-daily-upgrade.timer motd-news.timer \
      dpkg-db-backup.timer fstrim.timer \
      polkit.service \
 # Behave like a VM: the Docker base image's policy-rc.d (exit 101) stops apt from starting services.
 && rm -f /usr/sbin/policy-rc.d \
 # Per-container SSH host keys are generated at first boot, not baked into the image.
 && rm -f /etc/ssh/ssh_host_* \
 && mkdir -p /etc/kiln /root/.ssh /run/sshd && chmod 700 /root/.ssh \
 && printf 'PermitRootLogin prohibit-password\nPasswordAuthentication no\n' > /etc/ssh/sshd_config.d/10-kiln-sim.conf

COPY server/units/ /etc/systemd/system/
COPY server/bin/ /usr/local/sbin/
RUN chmod +x /usr/local/sbin/kiln-sim-* \
 # Sim only: apt downloads through the sim apt cache when it is reachable (DIRECT otherwise). Written after the
 # image's own apt-get, so it applies to what the agent installs at runtime.
 && printf 'Acquire::http::Proxy-Auto-Detect "/usr/local/sbin/kiln-sim-apt-proxy";\n' > /etc/apt/apt.conf.d/01kiln-sim-proxy \
 # Sim only: the registry of docker/compose builds is plain HTTP on the fleet network; Docker Hub pulls go
 # through the sim's pull-through cache (dockerd falls back to Docker Hub when the mirror is unreachable).
 && mkdir -p /etc/docker \
 && printf '{"insecure-registries": ["sim-registry:5000", "sim-hub-mirror:5000"], "registry-mirrors": ["http://sim-hub-mirror:5000"]}\n' > /etc/docker/daemon.json \
 && systemctl disable ssh.service \
 # Stock Ubuntu 24.04 socket activation (ssh.socket -> ssh.service). Enabling ssh.service as
 # well makes both bind :22 and one of them fail.
 && systemctl enable ssh.socket kiln-sim-hostkeys.service kiln-sim-trust.service kiln-sim-agent-link.service

STOPSIGNAL SIGRTMIN+3
ENTRYPOINT ["/usr/local/sbin/kiln-sim-entrypoint"]
