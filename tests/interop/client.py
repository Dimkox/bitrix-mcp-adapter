"""Talks to the demo server with the official MCP Python SDK (pip install mcp).

    php -S 127.0.0.1:8765 tests/interop/demo-server.php &
    python3 tests/interop/client.py http://127.0.0.1:8765/
"""
import asyncio
import sys


from mcp import ClientSession
from mcp.client.streamable_http import create_mcp_http_client, streamable_http_client

TOKEN = "bxmcp_0123456789abcdef0123456789abcdef"


async def main(url: str) -> None:
    http = create_mcp_http_client(headers={"Authorization": f"Bearer {TOKEN}"})
    async with streamable_http_client(url, http_client=http) as (read, write, *_):
        async with ClientSession(read, write) as session:
            init = await session.initialize()
            print("protocol", init.protocol_version, "server", init.server_info.name)
            tools = await session.list_tools()
            print("tools", sorted(t.name for t in tools.tools))
            echo = await session.call_tool("demo-echo", {"text": "hi", "times": 2})
            print("echo", echo.is_error, echo.structured_content)
            bad = await session.call_tool("demo-echo", {"times": 5})
            print("invalid", bad.is_error, bad.content[0].text)
            res = await session.read_resource("bitrix://demo/info")
            print("resource", res.contents[0].text)
            prompt = await session.get_prompt("demo-prompt", {"topic": "котах"})
            print("prompt", prompt.messages[0].content.text)
            await session.send_ping()
            print("ping ok")


asyncio.run(main(sys.argv[1]))
