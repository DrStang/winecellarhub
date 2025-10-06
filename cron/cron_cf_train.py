#!/usr/bin/env python3
import os, json, math, time
import pymysql
import numpy as np

# Optional: use LightFM if installed; else fallback to simple ALS-like SVD
USE_LIGHTFM = False
#try:
#    from lightfm import LightFM
#    from scipy.sparse import coo_matrix
#except Exception:
#    USE_LIGHTFM = False
#    from scipy.sparse import coo_matrix  # still need scipy; if not, pip install scipy
try:
    import implicit
    from scipy.sparse import coo_matrix
    USE_IMPLICIT = True
except Exception:
    USE_IMPLICIT = False

def train_implicit_als(mat, factors=32, reg=0.01, iters=20):
    model = implicit.als.AlternatingLeastSquares(
        factors=factors, regularization=reg, iterations=iters
    )
    model.fit(mat.T.tocsr())  # implicit fits on item-user
    return model.user_factors, model.item_factors

# ---- CONFIG (env or defaults)
DB_HOST = os.environ.get("DB_HOST", "100.66.175.61")
DB_USER = os.environ.get("DB_USER", "wine")
DB_PASS = os.environ.get("DB_PASS", "1qaz2wsx!QAZ@WSX")
DB_WINE = os.environ.get("DB_WINE_DB", "Wine")
DB_CATALOG = os.environ.get("WINELIST_DB", os.environ.get("DB_CATALOG_DB", "winelist"))

FACTOR_DIM = int(os.environ.get("CF_FACTORS", "32"))
MIN_INTERACTIONS_PER_USER = 3
MIN_INTERACTIONS_PER_ITEM = 2

# ---- DB helpers
def connect(db):
    return pymysql.connect(
        host=DB_HOST, user=DB_USER, password=DB_PASS,
        database=db, charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor
    )

def fetch_interactions():
    """
    Build implicit + explicit interaction triples (user_id, wine_id, weight).
    - From Wine.bottles: explicit my_rating (1..5) -> weight
    - From Wine.user_events: implicit weights for view/search/add/opened
    """
    conW = connect(DB_WINE)
    cur = conW.cursor()
    data = []

    # Explicit ratings (normalize 1..5 -> weights ~ 1..3)
    cur.execute("""
      SELECT user_id, wine_id, my_rating
      FROM bottles
      WHERE my_rating IS NOT NULL AND wine_id IS NOT NULL
    """)
    for r in cur.fetchall():
        u, i, rt = int(r["user_id"]), int(r["wine_id"]), float(r["my_rating"])
        w = 0.5 + (rt-1) * (2.5/4.0)  # 1->0.5, 5->3.0
        data.append((u, i, w))

    # Implicit events
    weights = {"view":0.2, "search_click":0.4, "added_to_cellar":1.0, "opened":0.1}
    cur.execute("SELECT user_id, wine_id, event, weight FROM user_events WHERE wine_id IS NOT NULL")
    for r in cur.fetchall():
        u, i = int(r["user_id"]), int(r["wine_id"])
        base = weights.get(r["event"], 0.1)
        w = base * float(r.get("weight") or 1.0)
        data.append((u, i, w))

    cur.close(); conW.close()
    return data

def build_matrix(triples):
    if not triples:
        return None, {}, {}
    users = sorted(set(t[0] for t in triples))
    items = sorted(set(t[1] for t in triples))
    u_index = {u:i for i,u in enumerate(users)}
    i_index = {i:j for j,i in enumerate(items)}
    rows, cols, vals = [], [], []
    # aggregate duplicates
    agg = {}
    for u,i,w in triples:
        key = (u,i)
        agg[key] = agg.get(key, 0.0) + w
    for (u,i),w in agg.items():
        rows.append(u_index[u]); cols.append(i_index[i]); vals.append(w)
    mat = coo_matrix((np.array(vals,dtype=np.float32), (np.array(rows), np.array(cols))),
                     shape=(len(users), len(items)))
    return mat.tocsr(), u_index, i_index

def train_lightfm(mat):
    model = LightFM(loss="warp", no_components=FACTOR_DIM, learning_rate=0.05)
    model.fit(mat, epochs=15, num_threads=4)
    U = model.get_user_representations()[0]  # (n_users, k)
    V = model.get_item_representations()[1]  # (n_items, k)
    return U, V

def train_simple_als(mat, reg=0.1, iters=10):
    # Very small fallback; not production-ALS, but works as baseline
    m, n = mat.shape
    U = np.random.normal(0, 0.1, size=(m, FACTOR_DIM)).astype(np.float32)
    V = np.random.normal(0, 0.1, size=(n, FACTOR_DIM)).astype(np.float32)
    mat = mat.tocoo()
    for _ in range(iters):
        # update users
        for u in range(m):
            idx = mat.row == u
            js = mat.col[idx]; r = mat.data[idx]
            if js.size == 0: continue
            Vj = V[js]                              # (t, k)
            A = Vj.T @ Vj + reg * np.eye(FACTOR_DIM)
            b = Vj.T @ r
            U[u] = np.linalg.solve(A, b)
        # update items
        for j in range(n):
            idx = mat.col == j
            us = mat.row[idx]; r = mat.data[idx]
            if us.size == 0: continue
            Uu = U[us]
            A = Uu.T @ Uu + reg * np.eye(FACTOR_DIM)
            b = Uu.T @ r
            V[j] = np.linalg.solve(A, b)
    return U, V

def save_factors(U, V, u_index, i_index):
    now = time.strftime("%Y-%m-%d %H:%M:%S")
    conW = connect(DB_WINE)
    curW = conW.cursor()
    # invert maps
    inv_u = {i:u for u,i in u_index.items()}
    inv_i = {j:i for i,j in i_index.items()}
    # write users
    curW.execute("DELETE FROM cf_user_factors WHERE 1=1")
    for i in range(U.shape[0]):
        uid = inv_u[i]
        curW.execute(
            "INSERT INTO cf_user_factors(user_id, factors, updated_at) VALUES(%s,%s,%s)",
            (uid, json.dumps(U[i].tolist()), now)
        )
    # write items
    curW.execute("DELETE FROM cf_wine_factors WHERE 1=1")
    for j in range(V.shape[0]):
        wid = inv_i[j]
        curW.execute(
            "INSERT INTO cf_wine_factors(wine_id, factors, updated_at) VALUES(%s,%s,%s)",
            (wid, json.dumps(V[j].tolist()), now)
        )
    conW.commit()
    curW.close(); conW.close()

def main():
    triples = fetch_interactions()
    mat, uidx, iidx = build_matrix(triples)
    if mat is None or mat.nnz == 0:
        print("No interactions; skipping.")
        return
    # prune cold users/items
    # (optional: could enforce MIN_INTERACTIONS here)
    if USE_IMPLICIT:
        U, V = train_implicit_als(mat, factors=FACTOR_DIM)
    elif USE_LIGHTFM:
        U, V = train_lightfm(mat)
    else:
        U, V = train_simple_als(mat)
    if USE_LIGHTFM:
        U,V = train_lightfm(mat)
    else:
        U,V = train_simple_als(mat)

    save_factors(U,V,uidx,iidx)
    print("CF training done: users=%d items=%d dim=%d" % (U.shape[0], V.shape[0], U.shape[1]))

if __name__ == "__main__":
    main()
