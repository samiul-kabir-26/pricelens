# syntax=docker/dockerfile:1.7
#
# PriceLens fixture farm: N static shop sites served by one nginx, picked by Host header.
#   docker build -t pricelens/fixture-farm load/fixture-farm
#   docker run --rm -p 8090:80 pricelens/fixture-farm
#   curl -H 'Host: shop-01.fixture.test' localhost:8090/p/sony-wh-1000xm5
# Changing FIXTURE_SITES also means updating the network aliases in docker-compose.loadtest.yml
# (python3 generate.py --sites N --print-aliases).

FROM python:3.13-alpine AS generate
ARG FIXTURE_SITES=10
ARG FIXTURE_SEED=42
WORKDIR /src
COPY generate.py .
RUN python3 generate.py --sites "${FIXTURE_SITES}" --seed "${FIXTURE_SEED}" --out /out

FROM nginx:1.28-alpine
COPY nginx.conf /etc/nginx/nginx.conf
COPY fixture-common.conf /etc/nginx/fixture-common.conf
COPY --from=generate /out/sites.conf /etc/nginx/fixture/sites.conf
COPY --from=generate /out/sites /srv/fixture-farm/sites
COPY --from=generate /out/catalog.json /srv/fixture-farm/catalog.json
RUN nginx -t
EXPOSE 80
HEALTHCHECK --interval=10s --timeout=3s --retries=3 CMD wget -qO- http://127.0.0.1/healthz >/dev/null || exit 1
