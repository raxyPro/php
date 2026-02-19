import tkinter as tk
from tkinter import ttk, messagebox


# Keep DB connection settings in this file (no app.config dependency).
DSN = "mysql:host=127.0.0.1;port=3306;dbname=stockdata;charset=utf8mb4"
DB_USER = "rax"
DB_PASS = "512"


def parse_mysql_dsn(dsn: str) -> dict:
    if not dsn.startswith("mysql:"):
        raise ValueError("DSN must start with 'mysql:'")
    payload = dsn[len("mysql:") :]
    out = {}
    for part in payload.split(";"):
        part = part.strip()
        if not part or "=" not in part:
            continue
        key, value = part.split("=", 1)
        out[key.strip().lower()] = value.strip()
    return {
        "host": out.get("host", "127.0.0.1"),
        "port": int(out.get("port", "3306")),
        "dbname": out.get("dbname", ""),
        "charset": out.get("charset", "utf8mb4"),
    }


def quote_ident(name: str) -> str:
    return "`" + name.replace("`", "``") + "`"


class DbClient:
    def __init__(self, host: str, port: int, user: str, password: str, charset: str):
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.charset = charset
        self.driver = None
        self._import_driver()

    def _import_driver(self) -> None:
        try:
            import mysql.connector  # type: ignore

            self.driver = ("mysql.connector", mysql.connector)
            return
        except Exception:
            pass
        try:
            import pymysql  # type: ignore

            self.driver = ("pymysql", pymysql)
            return
        except Exception:
            pass
        raise RuntimeError(
            "No MySQL driver found. Install one of: pip install mysql-connector-python OR pip install pymysql"
        )

    def connect(self, database: str = ""):
        name, drv = self.driver
        kwargs = {
            "host": self.host,
            "port": self.port,
            "user": self.user,
            "password": self.password,
            "charset": self.charset,
        }
        if database:
            kwargs["database"] = database
        if name == "mysql.connector":
            return drv.connect(**kwargs)
        return drv.connect(**kwargs, autocommit=True)

    def fetch_databases(self) -> list[str]:
        conn = self.connect("")
        try:
            cur = conn.cursor()
            cur.execute("SHOW DATABASES")
            rows = cur.fetchall()
            return [str(r[0]) for r in rows]
        finally:
            conn.close()

    def fetch_tables(self, database: str) -> list[str]:
        conn = self.connect(database)
        try:
            cur = conn.cursor()
            cur.execute("SHOW TABLES")
            rows = cur.fetchall()
            return [str(r[0]) for r in rows]
        finally:
            conn.close()

    def fetch_create_and_indexes(self, database: str, table: str) -> tuple[str, list[dict]]:
        conn = self.connect(database)
        try:
            cur = conn.cursor()
            cur.execute(f"SHOW CREATE TABLE {quote_ident(table)}")
            row = cur.fetchone()
            create_stmt = "" if not row else str(row[1])

            cur.execute(f"SHOW INDEX FROM {quote_ident(table)}")
            cols = [d[0] for d in cur.description] if cur.description else []
            idx_rows = []
            for raw in cur.fetchall():
                item = {}
                for i, col in enumerate(cols):
                    item[col] = raw[i]
                idx_rows.append(item)
            return create_stmt, idx_rows
        finally:
            conn.close()


class DbBrowserApp(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("MySQL DB Utility")
        self.geometry("1100x680")
        self.minsize(900, 560)

        cfg = parse_mysql_dsn(DSN)
        self.default_db = cfg["dbname"]
        self.client = DbClient(
            host=cfg["host"],
            port=cfg["port"],
            user=DB_USER,
            password=DB_PASS,
            charset=cfg["charset"],
        )

        self.db_var = tk.StringVar()
        self.status_var = tk.StringVar(value="Ready")
        self._build_ui()
        self._load_databases()

    def _build_ui(self) -> None:
        top = ttk.Frame(self, padding=10)
        top.pack(fill=tk.X)

        ttk.Label(top, text="Database:").pack(side=tk.LEFT)
        self.db_combo = ttk.Combobox(top, state="readonly", textvariable=self.db_var, width=35)
        self.db_combo.pack(side=tk.LEFT, padx=(8, 8))
        self.db_combo.bind("<<ComboboxSelected>>", self._on_db_change)
        ttk.Button(top, text="Refresh", command=self._load_databases).pack(side=tk.LEFT)

        pane = ttk.Panedwindow(self, orient=tk.HORIZONTAL)
        pane.pack(fill=tk.BOTH, expand=True, padx=10, pady=(0, 10))

        left = ttk.Frame(pane, padding=8)
        right = ttk.Frame(pane, padding=8)
        pane.add(left, weight=1)
        pane.add(right, weight=3)

        ttk.Label(left, text="Tables").pack(anchor="w")
        self.table_list = tk.Listbox(left, exportselection=False)
        self.table_list.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        self.table_list.bind("<<ListboxSelect>>", self._on_table_click)
        yscroll = ttk.Scrollbar(left, orient=tk.VERTICAL, command=self.table_list.yview)
        yscroll.pack(side=tk.RIGHT, fill=tk.Y)
        self.table_list.configure(yscrollcommand=yscroll.set)

        ttk.Label(right, text="Create Statement / Indexes").pack(anchor="w")
        self.create_text = tk.Text(right, wrap="none", font=("Consolas", 10))
        self.create_text.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        text_ys = ttk.Scrollbar(right, orient=tk.VERTICAL, command=self.create_text.yview)
        text_ys.pack(side=tk.RIGHT, fill=tk.Y)
        self.create_text.configure(yscrollcommand=text_ys.set)

        bottom = ttk.Frame(self, padding=(10, 0, 10, 10))
        bottom.pack(fill=tk.X)
        ttk.Label(bottom, textvariable=self.status_var).pack(anchor="w")

    def _set_status(self, text: str) -> None:
        self.status_var.set(text)

    def _show_error(self, title: str, err: Exception) -> None:
        self._set_status(f"Error: {err}")
        messagebox.showerror(title, str(err))

    def _load_databases(self) -> None:
        try:
            dbs = self.client.fetch_databases()
            self.db_combo["values"] = dbs
            selected = self.db_var.get()
            if self.default_db in dbs:
                selected = self.default_db
            elif selected not in dbs:
                selected = dbs[0] if dbs else ""
            self.db_var.set(selected)
            self._load_tables()
            self._set_status(f"Loaded {len(dbs)} databases")
        except Exception as err:
            self._show_error("Load Databases Failed", err)

    def _load_tables(self) -> None:
        db = self.db_var.get().strip()
        self.table_list.delete(0, tk.END)
        self.create_text.delete("1.0", tk.END)
        if not db:
            return
        try:
            tables = self.client.fetch_tables(db)
            for t in tables:
                self.table_list.insert(tk.END, t)
            self._set_status(f"{db}: {len(tables)} tables")
        except Exception as err:
            self._show_error("Load Tables Failed", err)

    def _on_db_change(self, _event=None) -> None:
        self._load_tables()

    def _on_table_click(self, _event=None) -> None:
        if not self.table_list.curselection():
            return
        db = self.db_var.get().strip()
        table = self.table_list.get(self.table_list.curselection()[0]).strip()
        if not db or not table:
            return
        try:
            create_stmt, indexes = self.client.fetch_create_and_indexes(db, table)
            lines = [f"-- {db}.{table}", "", create_stmt, "", "-- INDEXES --"]
            if indexes:
                for row in indexes:
                    lines.append(
                        f"{row.get('Key_name','')} | seq={row.get('Seq_in_index','')} | col={row.get('Column_name','')} | "
                        f"unique={0 if row.get('Non_unique') else 1} | type={row.get('Index_type','')}"
                    )
            else:
                lines.append("(No indexes)")
            self.create_text.delete("1.0", tk.END)
            self.create_text.insert("1.0", "\n".join(lines))
            self._set_status(f"Loaded create statement for {table}")
        except Exception as err:
            self._show_error("Load Create Statement Failed", err)


if __name__ == "__main__":
    app = DbBrowserApp()
    app.mainloop()
