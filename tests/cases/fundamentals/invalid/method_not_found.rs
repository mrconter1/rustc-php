// error: not found

struct S {
    x: i32,
}

impl S {
    fn get(&self) -> i32 {
        self.x
    }
}

fn main() {
    let s = S { x: 1 };
    s.no_such_method();
}
