// exit: 0
// stdout: 42
// stdout: 99

struct Wrapper<T> {
    value: T,
}

impl<T> Wrapper<T> {
    fn new(v: T) -> Wrapper<T> {
        Wrapper { value: v }
    }

    fn get(&self) -> T {
        self.value
    }
}

fn main() {
    let w1 = Wrapper::new(42);
    println!("{}", w1.get());
    let w2 = Wrapper::new(99);
    println!("{}", w2.get());
}
